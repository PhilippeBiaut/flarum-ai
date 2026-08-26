<?php

namespace Pbiaut\AiSeeder\Service;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Illuminate\Database\ConnectionInterface;
use Pbiaut\AiSeeder\Creator\CounterRefresher;
use Pbiaut\AiSeeder\Creator\TagProvisioner;
use Pbiaut\AiSeeder\Generator\GenerationContext;
use Pbiaut\AiSeeder\Generator\TagClassifier;
use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\Model\Item;
use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;
use Throwable;

/**
 * Tags discussions that already exist, in slices, exactly like BatchRunner does
 * for generation: same batch row, same progress, same pause and rollback.
 *
 * The one important difference: these discussions are not ours. Nothing is ever
 * created, edited or deleted here - only tag links are added, and only the ones
 * this run added are removed on rollback.
 */
class TaggingRunner
{
    public int $retryAfter = 2;

    public function __construct(
        protected ConnectionInterface $db,
        protected SeederSettings $settings,
        protected Client $client,
        protected TagClassifier $classifier,
        protected TagProvisioner $tags,
        protected CounterRefresher $counters,
        protected RunLogger $logs,
    ) {
    }

    /**
     * @param  callable(string):void|null  $log
     * @return bool  true when work remains
     */
    public function run(Batch $batch, ?callable $log = null): bool
    {
        $this->retryAfter = 2;

        $log = function (string $message) use ($batch, $log): void {
            $this->logs->write($batch->id, $message);

            if ($log !== null) {
                $log($message);
            }
        };

        if (! $this->client->isConfigured()) {
            return $this->fail($batch, 'No OpenAI API key is configured for this extension.');
        }

        if (! $this->tags->available()) {
            return $this->fail($batch, 'The Tags extension is not installed, so there is nothing to tag with.');
        }

        if ($batch->isHalted()) {
            return false;
        }

        $paths = $this->paths($batch);

        if ($paths === []) {
            return $this->fail($batch, 'No tags were given for this run.');
        }

        $context = GenerationContext::fromConfig($batch->config, $this->settings->forumTitle());
        $model = $batch->model ?: $this->settings->model();

        $batch->status = Batch::STATUS_RUNNING;
        $batch->started_at ??= Carbon::now();
        $batch->save();

        $this->client->resetUsage();
        $budget = $this->settings->callsPerRun();
        $requeue = false;

        try {
            while ($budget > 0) {
                if ($this->isHalted($batch)) {
                    $this->recordUsage($batch);

                    return false;
                }

                if (! $this->step($batch, $paths, $context, $model, $log)) {
                    break;
                }

                $budget--;
            }
        } catch (RunInterrupted $e) {
            $log('Backing off: '.$e->getMessage());
            $this->retryAfter = 60;
            $requeue = true;
        } catch (Throwable $e) {
            $this->recordUsage($batch);

            return $this->fail($batch, $e->getMessage());
        }

        $this->recordUsage($batch);

        if ($batch->items()->pending()->count() > 0 || $requeue) {
            $batch->status = Batch::STATUS_QUEUED;
            $batch->save();

            return true;
        }

        $this->finalise($batch, $log);

        return false;
    }

    /**
     * One API call: classify up to BATCH_SIZE threads and apply the result.
     *
     * @param  array<int, string>  $paths
     * @param  callable(string):void  $log
     * @return bool  false when there is nothing left to do
     */
    protected function step(Batch $batch, array $paths, GenerationContext $context, string $model, callable $log): bool
    {
        $items = $batch->items()
            ->pending()
            ->where('type', Item::TYPE_TAGGING)
            ->orderBy('position')
            ->limit(TagClassifier::BATCH_SIZE)
            ->get();

        if ($items->isEmpty()) {
            return false;
        }

        $discussions = [];
        $byId = [];

        foreach ($items as $item) {
            $discussion = Discussion::with('firstPost')->find($item->target_id);

            if ($discussion === null) {
                $item->status = Item::STATUS_SKIPPED;
                $item->error = 'The discussion no longer exists.';
                $item->save();
                continue;
            }

            $byId[$discussion->id] = $item;
            $discussions[] = [
                'id' => $discussion->id,
                'title' => (string) $discussion->title,
                // ->content runs the formatter in reverse, giving back the
                // markdown the author typed rather than Flarum's stored XML.
                'excerpt' => (string) ($discussion->firstPost->content ?? ''),
            ];
        }

        if ($discussions === []) {
            return true;
        }

        try {
            $assignments = $this->classifier->classify($discussions, $paths, $context, $model);
        } catch (OpenAiException $e) {
            $this->penalise($items, $e);

            return true;
        }

        $tagged = 0;
        $skipped = 0;

        foreach ($byId as $discussionId => $item) {
            $path = $assignments[$discussionId] ?? null;

            try {
                if ($path === null) {
                    $item->mergePayload(['assigned' => null]);
                    $item->status = Item::STATUS_DONE;
                    $item->save();
                    $skipped++;
                    continue;
                }

                $added = $this->attach($discussionId, $this->tags->resolve($path));

                $item->mergePayload(['assigned' => $path, 'added_tag_ids' => $added]);
                $item->status = Item::STATUS_DONE;
                $item->error = null;
                $item->save();

                $batch->increment('discussions_created');
                $tagged++;
            } catch (Throwable $e) {
                $this->logs->error($batch->id, 'Tagging discussion #'.$discussionId.' failed: '.$e->getMessage());
                $item->markFailed($e->getMessage());
            }
        }

        $log('Classified '.count($discussions).' discussion(s): '.$tagged.' tagged, '.$skipped.' left alone.');

        return true;
    }

    /**
     * Adds the tag links, and reports only the ones that were not already
     * there. Rollback removes exactly those, so a tag the discussion already
     * carried is never stripped.
     *
     * @param  array<int, int>  $tagIds
     * @return array<int, int>
     */
    protected function attach(int $discussionId, array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }

        $existing = $this->db->table('discussion_tag')
            ->where('discussion_id', $discussionId)
            ->pluck('tag_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $added = array_values(array_diff($tagIds, $existing));

        if ($added === []) {
            return [];
        }

        $this->db->table('discussion_tag')->insertOrIgnore(array_map(
            fn (int $tagId) => ['discussion_id' => $discussionId, 'tag_id' => $tagId],
            $added
        ));

        return $added;
    }

    /**
     * @param  iterable<Item>  $items
     */
    protected function penalise(iterable $items, OpenAiException $error): void
    {
        foreach ($items as $item) {
            $item->markFailed($error->getMessage());
        }

        if (in_array($error->status, [401, 403], true)) {
            throw $error;
        }

        if ($error->retryable) {
            throw new RunInterrupted($error->getMessage());
        }
    }

    /**
     * @return array<int, string>
     */
    protected function paths(Batch $batch): array
    {
        $paths = [];

        foreach ($batch->config['tags'] ?? [] as $tag) {
            if (is_array($tag) && isset($tag['path']) && is_string($tag['path'])) {
                $paths[] = $tag['path'];
            }
        }

        return $paths;
    }

    protected function isHalted(Batch $batch): bool
    {
        $fresh = Batch::find($batch->id);

        return $fresh === null || in_array($fresh->status, Batch::HALTED, true);
    }

    protected function recordUsage(Batch $batch): void
    {
        $usage = $this->client->usage();

        $batch->refresh();
        $batch->tokens_in += $usage['tokens_in'];
        $batch->tokens_out += $usage['tokens_out'];
        $batch->api_calls += $usage['calls'];
        $batch->failed_count = $batch->items()->where('status', Item::STATUS_FAILED)->count();
        $batch->save();

        $this->client->resetUsage();
    }

    /**
     * @param  callable(string):void  $log
     */
    protected function finalise(Batch $batch, callable $log): void
    {
        $this->counters->refreshTags();

        $batch->refresh();
        $batch->status = Batch::STATUS_COMPLETED;
        $batch->finished_at = Carbon::now();
        $batch->save();

        $log('Batch #'.$batch->id.' finished: '.$batch->discussions_created.' discussion(s) tagged, '
            .$batch->failed_count.' failed.');
    }

    protected function fail(Batch $batch, string $message): bool
    {
        $this->logs->error($batch->id, $message);

        $batch->status = Batch::STATUS_FAILED;
        $batch->error = mb_substr($message, 0, 2000);
        $batch->finished_at = Carbon::now();
        $batch->save();

        return false;
    }
}
