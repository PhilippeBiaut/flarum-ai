<?php

namespace Pbiaut\AiSeeder\Service;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Pbiaut\AiSeeder\Creator\CounterRefresher;
use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\Model\Item;
use Throwable;

/**
 * Undoes a batch, in the reverse order it was built: replies, then discussions
 * (whose opening post goes with them through the foreign key), then members.
 *
 * Works in slices like the runner, so reverting a large batch does not need one
 * enormous job either. Item rows are dropped as their target disappears, which
 * makes the operation resumable and idempotent.
 */
class RevertRunner
{
    public const CHUNK = 100;

    public function __construct(protected CounterRefresher $counters)
    {
    }

    /**
     * @param  callable(string):void|null  $log
     * @return bool  true when work remains
     */
    public function run(Batch $batch, ?callable $log = null): bool
    {
        $log ??= static fn (string $message) => null;

        $batch->status = Batch::STATUS_REVERTING;
        $batch->save();

        $tagIds = $this->plannedTagIds($batch);

        foreach ([Item::TYPE_REPLY, Item::TYPE_DISCUSSION, Item::TYPE_USER] as $type) {
            $items = $batch->items()
                ->where('type', $type)
                ->orderByDesc('id')
                ->limit(self::CHUNK)
                ->get();

            if ($items->isEmpty()) {
                continue;
            }

            foreach ($items as $item) {
                try {
                    $this->deleteTarget($item);
                } catch (Throwable $e) {
                    $log('Could not delete '.$item->type.' #'.$item->target_id.': '.$e->getMessage());
                }

                $item->delete();
            }

            $log('Removed '.$items->count().' '.$type.'(s).');

            $this->syncCounters($batch);

            return true;
        }

        $this->counters->refreshTags($tagIds);

        $batch->refresh();
        $batch->status = Batch::STATUS_REVERTED;
        $batch->users_created = 0;
        $batch->discussions_created = 0;
        $batch->replies_created = 0;
        $batch->failed_count = 0;
        $batch->finished_at = Carbon::now();
        $batch->save();

        $log('Batch #'.$batch->id.' fully reverted.');

        return false;
    }

    protected function deleteTarget(Item $item): void
    {
        if ($item->target_id === null) {
            return;
        }

        $model = match ($item->type) {
            Item::TYPE_REPLY => Post::find($item->target_id),
            Item::TYPE_DISCUSSION => Discussion::find($item->target_id),
            Item::TYPE_USER => User::find($item->target_id),
            default => null,
        };

        // The root admin is protected by the User model; never our own creation
        // anyway, but the guard costs nothing.
        if ($model instanceof User && $model->id === 1) {
            return;
        }

        $model?->delete();
    }

    protected function syncCounters(Batch $batch): void
    {
        $batch->refresh();
        $batch->users_created = $batch->items()->where('type', Item::TYPE_USER)->whereNotNull('target_id')->count();
        $batch->discussions_created = $batch->items()->where('type', Item::TYPE_DISCUSSION)->whereNotNull('target_id')->count();
        $batch->replies_created = $batch->items()->where('type', Item::TYPE_REPLY)->whereNotNull('target_id')->count();
        $batch->save();
    }

    /**
     * Tags the batch actually attached, read back from the item payloads.
     *
     * Tags the run *created* are deliberately left in place: by the time a
     * rollback happens a real member may have used one, and deleting it would
     * strip the tag off their discussion too. Only the counts are corrected.
     *
     * @return array<int, int>
     */
    protected function plannedTagIds(Batch $batch): array
    {
        $ids = [];

        $rows = $batch->items()
            ->where('type', Item::TYPE_DISCUSSION)
            ->whereNotNull('payload')
            ->get();

        foreach ($rows as $item) {
            foreach ((array) $item->get('tag_ids', []) as $id) {
                if (is_numeric($id)) {
                    $ids[(int) $id] = true;
                }
            }
        }

        return array_keys($ids);
    }
}
