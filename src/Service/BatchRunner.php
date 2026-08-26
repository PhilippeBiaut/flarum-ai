<?php

namespace Pbiaut\AiSeeder\Service;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;
use Pbiaut\AiSeeder\Creator\CounterRefresher;
use Pbiaut\AiSeeder\Creator\DiscussionCreator;
use Pbiaut\AiSeeder\Creator\ReplyCreator;
use Pbiaut\AiSeeder\Creator\SocialSignals;
use Pbiaut\AiSeeder\Creator\TagProvisioner;
use Pbiaut\AiSeeder\Creator\UserCreator;
use Pbiaut\AiSeeder\Generator\DiscussionBodyGenerator;
use Pbiaut\AiSeeder\Generator\GenerationContext;
use Pbiaut\AiSeeder\Generator\PersonaGenerator;
use Pbiaut\AiSeeder\Generator\ReplyQuality;
use Pbiaut\AiSeeder\Generator\ReplyBundleGenerator;
use Pbiaut\AiSeeder\Generator\TopicGenerator;
use Pbiaut\AiSeeder\Generator\VoiceQuirks;
use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\Model\Item;
use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;
use Pbiaut\AiSeeder\Planner\Rng;
use Throwable;

/**
 * Executes one slice of a batch: a bounded number of OpenAI calls, then hands
 * back so the job can re-queue itself.
 *
 * Working in slices rather than one long job is what makes pause, cancel and
 * crash-recovery work, and keeps a single queue job well under any worker
 * timeout even for a several-thousand-post run.
 */
class BatchRunner
{
    public function __construct(
        protected SeederSettings $settings,
        protected Client $client,
        protected PersonaGenerator $personas,
        protected TopicGenerator $topics,
        protected DiscussionBodyGenerator $bodies,
        protected ReplyBundleGenerator $replies,
        protected UserCreator $userCreator,
        protected DiscussionCreator $discussionCreator,
        protected ReplyCreator $replyCreator,
        protected CounterRefresher $counters,
        protected TagProvisioner $tags,
        protected RunLogger $logs,
        protected SocialSignals $social,
        protected VoiceQuirks $voices,
    ) {
    }

    /** Writes to the batch's run log and, when running from the CLI, to stdout. */
    protected function emitter(Batch $batch, ?callable $log): callable
    {
        return function (string $message, string $level = RunLogger::INFO) use ($batch, $log): void {
            $this->logs->write($batch->id, $message, $level);

            if ($log !== null) {
                $log($message);
            }
        };
    }

    /**
     * How long the job should wait before picking up the next slice. Bumped
     * when OpenAI asked us to slow down, so a rate-limited run does not
     * hot-loop through the queue.
     */
    public int $retryAfter = 2;

    /**
     * @param  callable(string):void|null  $log
     * @return bool  true when work remains and the job should re-queue itself
     */
    public function run(Batch $batch, ?callable $log = null, ?int $budget = null): bool
    {
        $this->retryAfter = 2;

        $log = $this->emitter($batch, $log);

        if (! $this->client->isConfigured()) {
            return $this->fail($batch, 'No OpenAI API key is configured for this extension.');
        }

        if ($batch->isHalted()) {
            $log('Batch #'.$batch->id.' is '.$batch->status.'; nothing to do.');

            return false;
        }

        $context = GenerationContext::fromConfig($batch->config, $this->settings->forumTitle());
        $model = $batch->model ?: $this->settings->model();

        $batch->status = Batch::STATUS_RUNNING;
        $batch->started_at ??= Carbon::now();
        $batch->save();

        $this->client->resetUsage();
        $budget ??= $this->settings->callsPerRun();
        $requeue = false;

        try {
            while ($budget > 0) {
                if ($this->isHalted($batch)) {
                    $this->recordUsage($batch);

                    return false;
                }

                $used = $this->step($batch, $context, $model, $log);

                if ($used === 0) {
                    break;
                }

                $budget -= $used;
            }
        } catch (RunInterrupted $e) {
            $log('Backing off for now: '.$e->getMessage());
            $this->retryAfter = 60;
            $requeue = true;
        } catch (Throwable $e) {
            $this->recordUsage($batch);

            return $this->fail($batch, $e->getMessage());
        }

        $this->recordUsage($batch);

        $pending = $batch->items()->pending()->count();

        if ($pending > 0 || $requeue) {
            $batch->status = Batch::STATUS_QUEUED;
            $batch->save();

            return true;
        }

        $this->finalise($batch, $log);

        return false;
    }

    /**
     * Performs one unit of work and returns how many OpenAI calls it used.
     * Returns 0 when there is nothing left to do.
     *
     * @param  callable(string):void  $log
     */
    protected function step(Batch $batch, GenerationContext $context, string $model, callable $log): int
    {
        return $this->stepMembers($batch, $context, $model, $log)
            ?? $this->stepTitles($batch, $context, $model, $log)
            ?? $this->stepDiscussions($batch, $context, $model, $log)
            ?? $this->stepReplies($batch, $context, $model, $log)
            ?? 0;
    }

    /** One call invents up to 25 members; they are then created immediately. */
    protected function stepMembers(Batch $batch, GenerationContext $context, string $model, callable $log): ?int
    {
        /** @var Collection<int, Item> $items */
        $items = $batch->items()
            ->pending()
            ->where('type', Item::TYPE_USER)
            ->orderBy('scheduled_at')
            ->limit(PersonaGenerator::BATCH_SIZE)
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $taken = User::query()->pluck('username')->all();

        try {
            $personas = $this->personas->generate(
                $items->count(),
                $context,
                $taken,
                $model,
                new Rng(($batch->seed ?: 1) + $items->first()->id)
            );
        } catch (OpenAiException $e) {
            $this->penalise($items, $e);

            return 1;
        }

        foreach ($items as $index => $item) {
            $persona = $personas[$index] ?? null;

            if ($persona === null) {
                $item->markFailed('The model returned fewer members than requested.');
                continue;
            }

            try {
                $user = $this->userCreator->create($persona, $item->scheduled_at);

                $item->target_id = $user->id;
                $item->mergePayload($persona + ['created_username' => $user->username]);
                $item->status = Item::STATUS_DONE;
                $item->error = null;
                $item->save();

                $batch->increment('users_created');
            } catch (Throwable $e) {
                $this->logs->error($batch->id, 'Member failed: '.$e->getMessage());
                $item->markFailed($e->getMessage());
            }
        }

        $log('Created '.$items->count().' member(s).');

        return 1;
    }

    /** One call names up to 20 threads; titles are stored, not yet created. */
    protected function stepTitles(Batch $batch, GenerationContext $context, string $model, callable $log): ?int
    {
        // Titles live in the JSON payload, so "not yet named" is expressed as a
        // LIKE on the payload column. Portable across MySQL, PostgreSQL and
        // SQLite, and the table only ever holds this batch's rows.
        /** @var Collection<int, Item> $items */
        $items = $batch->items()
            ->pending()
            ->where('type', Item::TYPE_DISCUSSION)
            ->where(function ($query) {
                $query->whereNull('payload')->orWhere('payload', 'not like', '%"title":%');
            })
            ->orderBy('scheduled_at')
            ->limit(TopicGenerator::BATCH_SIZE)
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $tagNames = $items->map(fn (Item $item) => $item->get('tag_name'))->all();
        $existing = $this->existingTitles($batch);

        try {
            $titles = $this->topics->generate($tagNames, $context, $existing, $model);
        } catch (OpenAiException $e) {
            $this->penalise($items, $e);

            return 1;
        }

        // The prompt only ever sees a window of previous titles, so at a few
        // hundred discussions it starts repeating ones that scrolled out of it.
        // Checked here against every title in the batch instead.
        $taken = $this->titleKeys($batch);
        $named = 0;
        $duplicates = 0;

        foreach ($items as $index => $item) {
            $title = $titles[$index] ?? null;

            if ($title === null || trim($title) === '') {
                continue;
            }

            $attempts = (int) $item->get('title_attempts', 0);

            if ($attempts < 3 && $this->isDuplicateTitle($title, $taken)) {
                // Left unnamed so the next pass tries again, this time with the
                // colliding title inside the prompt's window. Bounded, because
                // a forum genuinely does carry near-identical threads and we
                // must not loop forever chasing perfection.
                $item->mergePayload(['title_attempts' => $attempts + 1]);
                $item->save();
                $duplicates++;
                continue;
            }

            $taken[] = ReplyQuality::normalise($title);

            $item->mergePayload(['title' => $title]);
            $item->save();
            $named++;
        }

        $log('Named '.$named.' discussion(s)'
            .($duplicates > 0 ? ', '.$duplicates.' rejected as duplicate titles' : '').'.');

        return 1;
    }

    /**
     * Every title already used in this batch, normalised for comparison.
     *
     * @return array<int, string>
     */
    protected function titleKeys(Batch $batch): array
    {
        return $batch->items()
            ->where('type', Item::TYPE_DISCUSSION)
            ->where('payload', 'like', '%"title":%')
            ->get()
            ->map(fn (Item $item) => ReplyQuality::normalise((string) $item->get('title', '')))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $taken  normalised titles already used
     */
    protected function isDuplicateTitle(string $title, array $taken): bool
    {
        $key = ReplyQuality::normalise($title);

        foreach ($taken as $existing) {
            // 0.55, measured against titles a real 501-discussion run produced:
            // "ras le bol des accusations de racisme" scores 0.556 against
            // "ras le bol des accusations au boulot", while two genuinely
            // different threads on one subject stay under 0.2 - and a real forum
            // does carry those.
            if (ReplyQuality::similarity($key, $existing) >= 0.55) {
                return true;
            }
        }

        return false;
    }

    /** One call per opening post, then the discussion is created. */
    protected function stepDiscussions(Batch $batch, GenerationContext $context, string $model, callable $log): ?int
    {
        /** @var Item|null $item */
        $item = $batch->items()
            ->pending()
            ->where('type', Item::TYPE_DISCUSSION)
            ->orderBy('scheduled_at')
            ->first();

        if ($item === null || ! $item->get('title')) {
            return null;
        }

        $author = $this->authorOf($item);

        if ($author === null) {
            $item->markFailed('The planned author could not be created.');

            return 1;
        }

        try {
            $content = $this->bodies->generate(
                (string) $item->get('title'),
                $this->personaOf($item),
                // The full path gives the model more to work with than the leaf
                // alone: "Voyage > Voyages France" says a good deal more than
                // "Voyages France".
                $item->get('tag_path') ?: $item->get('tag_name'),
                $context,
                $model
            );
        } catch (OpenAiException $e) {
            $this->penalise(collect([$item]), $e);

            return 1;
        }

        try {
            // Resolves the path against existing tags and creates what is
            // missing, parent first. Returns both ids for a nested path, so the
            // discussion carries its primary tag as well as the child.
            $tagIds = $this->tags->resolve((string) $item->get('tag_path', ''));

            $content = $this->inVoice($content, $this->personaOf($item), $batch, $item);

            $discussion = $this->discussionCreator->create(
                (string) $item->get('title'),
                $content,
                $author,
                $item->scheduled_at,
                $tagIds
            );

            $item->target_id = $discussion->id;
            $item->mergePayload([
                'content' => $content,
                'first_post_id' => $discussion->first_post_id,
                'tag_ids' => $tagIds,
            ]);
            $item->status = Item::STATUS_DONE;
            $item->error = null;
            $item->save();

            $batch->increment('discussions_created');
            $log('Opened discussion "'.$item->get('title').'".');
        } catch (Throwable $e) {
            $this->logs->error($batch->id, 'Discussion failed: '.$e->getMessage());
            $item->markFailed($e->getMessage());
        }

        return 1;
    }

    /** One call writes a whole thread's replies, which are then created in order. */
    protected function stepReplies(Batch $batch, GenerationContext $context, string $model, callable $log): ?int
    {
        /** @var Item|null $parent */
        $parent = $batch->items()
            ->where('type', Item::TYPE_DISCUSSION)
            ->where('status', Item::STATUS_DONE)
            ->whereNotNull('target_id')
            ->whereIn('id', function ($query) use ($batch) {
                $query->from('ai_seeder_items')
                    ->select('parent_item_id')
                    ->where('batch_id', $batch->id)
                    ->where('type', Item::TYPE_REPLY)
                    ->where('status', Item::STATUS_PENDING);
            })
            ->orderBy('scheduled_at')
            ->first();

        if ($parent === null) {
            return null;
        }

        $discussion = Discussion::find($parent->target_id);

        if ($discussion === null) {
            $batch->items()
                ->where('parent_item_id', $parent->id)
                ->pending()
                ->update(['status' => Item::STATUS_SKIPPED, 'error' => 'The parent discussion no longer exists.']);

            return 1;
        }

        /** @var Collection<int, Item> $items */
        $items = $batch->items()
            ->pending()
            ->where('type', Item::TYPE_REPLY)
            ->where('parent_item_id', $parent->id)
            ->orderBy('scheduled_at')
            ->orderBy('position')
            ->limit(ReplyBundleGenerator::MAX_PER_CALL)
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $personas = [];
        $authors = [];
        $lengths = [];
        $targets = [];
        $types = [];

        foreach ($items as $item) {
            $personas[] = $this->personaOf($item);
            $authors[] = $this->authorOf($item);
            $lengths[] = (int) $item->get('words', 90);
            // Which message of the thread this reply answers, decided at
            // planning time. 0 is the opening post.
            $targets[] = (int) $item->get('replies_to', 0);
            $types[] = (string) $item->get('type', 'answer');
        }

        try {
            $bodies = $this->replies->generate(
                (string) $parent->get('title'),
                (string) $parent->get('content'),
                $this->personaOf($parent),
                $personas,
                $context,
                $this->writtenReplies($batch, $parent),
                $model,
                $lengths,
                $targets,
                $types
            );
        } catch (OpenAiException $e) {
            $this->penalise($items, $e);

            return 1;
        }

        $rejected = 0;

        foreach ($items as $index => $item) {
            $author = $authors[$index];

            if ($author === null) {
                $item->markFailed('The planned author could not be created.');
                continue;
            }

            $body = $bodies[$index] ?? null;

            if ($body === null || trim($body) === '') {
                // Duplicate, assistant tell, or simply missing from the answer.
                // Left pending so the next pass regenerates it: filling the gap
                // with a copy of another reply is what put duplicates on the
                // forum before.
                $item->markFailed('The reply was rejected as unusable or duplicate; it will be regenerated.');
                $rejected++;
                continue;
            }

            try {
                $body = $this->social->linkMentions($body, $this->participants($batch, $parent));
                $body = $this->inVoice($body, $personas[$index], $batch, $item);

                $post = $this->replyCreator->create($discussion, $body, $author, $item->scheduled_at);

                $this->social->recordMentions($post->id, $body, $post->created_at);

                $item->target_id = $post->id;
                $item->mergePayload(['content' => $body, 'author' => $author->username]);
                $item->status = Item::STATUS_DONE;
                $item->error = null;
                $item->save();

                $batch->increment('replies_created');
            } catch (Throwable $e) {
                $this->logs->error($batch->id, 'Reply failed: '.$e->getMessage());
                $item->markFailed($e->getMessage());
            }
        }

        $log('Wrote '.($items->count() - $rejected).' reply/replies in "'.$parent->get('title').'"'
            .($rejected > 0 ? ', '.$rejected.' rejected as duplicate or unusable' : '').'.');

        return 1;
    }

    /**
     * Members who have already written in this thread, so a name the model used
     * can be turned into a real mention.
     *
     * @return array<int, User>
     */
    protected function participants(Batch $batch, Item $parent): array
    {
        $ids = $batch->items()
            ->where(function ($query) use ($parent) {
                $query->where('id', $parent->id)->orWhere('parent_item_id', $parent->id);
            })
            ->where('status', Item::STATUS_DONE)
            ->pluck('author_item_id')
            ->filter()
            ->unique();

        $targets = Item::whereIn('id', $ids)->whereNotNull('target_id')->pluck('target_id');

        return User::whereIn('id', $targets)->get()->all();
    }

    /**
     * Earlier replies of the same thread, so a long thread generated over
     * several calls stays one continuous conversation.
     *
     * @return array<int, array{author: string, content: string}>
     */
    protected function writtenReplies(Batch $batch, Item $parent): array
    {
        return $batch->items()
            ->where('type', Item::TYPE_REPLY)
            ->where('parent_item_id', $parent->id)
            ->where('status', Item::STATUS_DONE)
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Item $item) => [
                'author' => (string) $item->get('author', 'a member'),
                'content' => (string) $item->get('content', ''),
            ])
            ->filter(fn (array $reply) => $reply['content'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function existingTitles(Batch $batch): array
    {
        return $batch->items()
            ->where('type', Item::TYPE_DISCUSSION)
            ->where('payload', 'like', '%"title":%')
            ->orderByDesc('id')
            ->limit(TopicGenerator::CONTEXT_TITLES)
            ->get()
            ->map(fn (Item $item) => (string) $item->get('title', ''))
            ->filter()
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * Applies the author's writing habits.
     *
     * Runs after mentions are linked, and VoiceQuirks masks mentions, code,
     * links and quoted lines, so lowercasing or stripping accents can never
     * break the formatter. Seeded per item, so a rerun reproduces the text.
     *
     * @param  array<string, mixed>  $persona
     */
    protected function inVoice(string $text, array $persona, Batch $batch, Item $item): string
    {
        $quirks = $persona['quirks'] ?? [];

        if (! is_array($quirks) || $quirks === []) {
            return $text;
        }

        return $this->voices->apply($text, $quirks, new Rng(($batch->seed ?: 1) + $item->id));
    }

    protected function authorOf(Item $item): ?User
    {
        $authorItem = $this->authorItem($item);

        return $authorItem?->target_id ? User::find($authorItem->target_id) : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function personaOf(Item $item): array
    {
        return $this->authorItem($item)?->payload ?? ['username' => 'member'];
    }

    /** @var array<int, Item|null> */
    private array $authorCache = [];

    protected function authorItem(Item $item): ?Item
    {
        if ($item->author_item_id === null) {
            return null;
        }

        return $this->authorCache[$item->author_item_id] ??= Item::find($item->author_item_id);
    }

    /**
     * @param  iterable<Item>  $items
     */
    protected function penalise(iterable $items, OpenAiException $error): void
    {
        foreach ($items as $item) {
            $item->markFailed($error->getMessage());
        }

        // A bad key or a forbidden model will fail identically forever: stop the
        // whole batch with a clear message rather than chewing through items.
        if (in_array($error->status, [401, 403], true)) {
            throw $error;
        }

        // Transient trouble: give the queue a chance to come back later rather
        // than burning through every remaining item with the same error.
        if ($error->retryable) {
            throw new RunInterrupted($error->getMessage());
        }
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
        $log('Refreshing counters...');

        $userIds = $batch->items()->where('type', Item::TYPE_USER)->whereNotNull('target_id')->pluck('target_id')->all();
        $discussionIds = $batch->items()->where('type', Item::TYPE_DISCUSSION)->whereNotNull('target_id')->pluck('target_id')->all();

        $this->counters->refreshDiscussions(array_map('intval', $discussionIds));
        $this->counters->refreshUsers(array_map('intval', $userIds));
        $this->counters->refreshTags();

        // Costs nothing and changes how the forum reads more than any prompt
        // tweak: pages with likes on them, and members who have been seen.
        $this->addSocialSignals($batch, array_map('intval', $userIds), $log);

        $batch->refresh();
        $batch->status = $batch->failed_count > 0 && $batch->totalCreated() === 0
            ? Batch::STATUS_FAILED
            : Batch::STATUS_COMPLETED;
        $batch->finished_at = Carbon::now();
        $batch->save();

        $log('Batch #'.$batch->id.' finished: '.$batch->users_created.' members, '
            .$batch->discussions_created.' discussions, '.$batch->replies_created.' replies, '
            .$batch->failed_count.' failed.');
    }

    /**
     * Likes and last-seen dates, drawn from the batch's own seed so a rerun of
     * the same plan produces the same forum.
     *
     * @param  array<int, int>  $userIds
     * @param  callable(string):void  $log
     */
    protected function addSocialSignals(Batch $batch, array $userIds, callable $log): void
    {
        if ($userIds === []) {
            return;
        }

        $rng = new Rng($batch->seed ?: 1);

        $candidates = User::whereIn('id', $userIds)
            ->get()
            ->map(fn (User $user) => ['id' => (int) $user->id, 'joined_at' => $user->joined_at])
            ->all();

        $postIds = $batch->items()
            ->whereIn('type', [Item::TYPE_REPLY])
            ->whereNotNull('target_id')
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Opening posts are worth liking too; they live on the discussion item.
        foreach ($batch->items()->where('type', Item::TYPE_DISCUSSION)->whereNotNull('payload')->get() as $item) {
            $firstPost = $item->get('first_post_id');

            if ($firstPost) {
                $postIds[] = (int) $firstPost;
            }
        }

        if ($postIds !== []) {
            $posts = Post::whereIn('id', $postIds)
                ->get()
                ->map(fn (Post $post) => [
                    'id' => (int) $post->id,
                    'user_id' => (int) $post->user_id,
                    'created_at' => $post->created_at,
                ])
                ->all();

            $likes = $this->social->like($posts, $candidates, $rng);

            if ($likes > 0) {
                $log('Added '.$likes.' like(s).');
            }
        }

        $this->social->refreshLastSeen($userIds, $rng);
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
