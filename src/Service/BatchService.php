<?php

namespace Pbiaut\AiSeeder\Service;

use Carbon\Carbon;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Pbiaut\AiSeeder\Creator\Dates;
use Pbiaut\AiSeeder\Job\ProcessBatchJob;
use Pbiaut\AiSeeder\Job\RevertBatchJob;
use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\Model\Item;
use Pbiaut\AiSeeder\Planner\InvalidConfigException;
use Pbiaut\AiSeeder\Planner\PlanConfig;
use Pbiaut\AiSeeder\Planner\PlanResult;
use Pbiaut\AiSeeder\Planner\SchedulePlanner;

/**
 * Turns an admin form into a plan, a plan into rows, and rows into a queued
 * run. Also owns the lifecycle transitions (pause / resume / cancel / revert).
 */
class BatchService
{
    protected const INSERT_CHUNK = 500;

    public function __construct(
        protected ConnectionInterface $db,
        protected SchedulePlanner $planner,
        protected SeederSettings $settings,
        protected CostEstimator $estimator,
        protected Queue $queue,
    ) {
    }

    /**
     * Dry run: computes the calendar and the cost estimate without persisting
     * anything and without contacting OpenAI.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function preview(array $config): array
    {
        if ($this->isTagging($config)) {
            return $this->previewTagging($config);
        }

        $planConfig = PlanConfig::fromArray($config, $this->settings->timezone());
        $plan = $this->planner->plan($planConfig);

        return array_merge($plan->toSummaryArray(), [
            'mode' => Batch::MODE_GENERATE,
            'config' => $planConfig->toArray(),
            'estimate' => $this->estimator->estimate($planConfig, $plan),
        ]);
    }

    /**
     * Counts the discussions a tagging run would look at, and what classifying
     * them would cost. No OpenAI call, nothing written.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function previewTagging(array $config): array
    {
        $planConfig = PlanConfig::fromArray(array_merge($config, [
            // The volume fields are meaningless in this mode; satisfy the
            // validator without asking the admin to fill them in.
            'users' => 0, 'discussions' => 0, 'replies' => 0,
        ]), $this->settings->timezone());

        $errors = [];

        if ($planConfig->tags === []) {
            $errors['tags'] = 'at least one tag is required to classify into';
        }

        $scope = (string) ($config['scope'] ?? DiscussionScope::UNTAGGED);

        if (! in_array($scope, DiscussionScope::SCOPES, true)) {
            $errors['scope'] = 'must be one of '.implode(', ', DiscussionScope::SCOPES);
        }

        if ($errors !== []) {
            throw new InvalidConfigException($errors);
        }

        $matched = DiscussionScope::count($config);

        return [
            'mode' => Batch::MODE_TAG,
            'matched' => $matched,
            'scope' => $scope,
            'tags' => array_column($planConfig->tags, 'path'),
            'config' => array_merge($planConfig->toArray(), ['mode' => Batch::MODE_TAG, 'scope' => $scope]),
            'estimate' => $this->estimator->estimateTagging($matched),
            'warnings' => [],
            'days' => [],
            'totals' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function create(array $config): Batch
    {
        if ($this->isTagging($config)) {
            return $this->createTagging($config);
        }

        return $this->createGeneration($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function isTagging(array $config): bool
    {
        return ($config['mode'] ?? Batch::MODE_GENERATE) === Batch::MODE_TAG;
    }

    /**
     * One item per discussion to classify, so the run is resumable and each
     * thread's outcome is traced individually.
     *
     * @param  array<string, mixed>  $config
     */
    protected function createTagging(array $config): Batch
    {
        $preview = $this->previewTagging($config);

        $batch = $this->db->transaction(function () use ($preview, $config) {
            $batch = new Batch();
            $batch->mode = Batch::MODE_TAG;
            $batch->status = Batch::STATUS_QUEUED;
            $batch->config = $preview['config'];
            $batch->plan_summary = ['matched' => $preview['matched'], 'tags' => $preview['tags']];
            $batch->model = (string) ($config['model'] ?? $this->settings->model());
            $batch->discussions_planned = $preview['matched'];
            $batch->created_at = Carbon::now();
            $batch->save();

            $query = DiscussionScope::query($config);
            $limit = DiscussionScope::limit($config);

            if ($limit > 0) {
                $query->limit($limit);
            }

            $rows = [];
            $position = 0;

            foreach ($query->pluck('id') as $discussionId) {
                $rows[] = [
                    'batch_id' => $batch->id,
                    'type' => Item::TYPE_TAGGING,
                    'target_id' => (int) $discussionId,
                    'scheduled_at' => Carbon::now()->format('Y-m-d H:i:s'),
                    'position' => $position++,
                    'status' => Item::STATUS_PENDING,
                ];
            }

            $this->insertRows($rows);

            return $batch;
        });

        $this->settings->rememberConfig($preview['config']);
        $this->queue->push(new ProcessBatchJob($batch->id));

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createGeneration(array $config): Batch
    {
        $planConfig = PlanConfig::fromArray($config, $this->settings->timezone());
        $plan = $this->planner->plan($planConfig);

        $batch = $this->db->transaction(function () use ($planConfig, $plan) {
            $batch = new Batch();
            $batch->mode = Batch::MODE_GENERATE;
            $batch->status = Batch::STATUS_QUEUED;
            $batch->config = $planConfig->toArray();
            $batch->plan_summary = $plan->toSummaryArray();
            $batch->model = (string) ($planConfig->generation('model') ?: $this->settings->model());
            $batch->seed = $planConfig->seed;
            $batch->users_planned = count($plan->users);
            $batch->discussions_planned = count($plan->discussions);
            $batch->replies_planned = $plan->replyCount();
            $batch->created_at = Carbon::now();
            $batch->save();

            $this->persistItems($batch, $plan);

            return $batch;
        });

        $this->settings->rememberConfig($planConfig->toArray());
        $this->queue->push(new ProcessBatchJob($batch->id));

        return $batch;
    }

    public function pause(Batch $batch): Batch
    {
        if (in_array($batch->status, [Batch::STATUS_QUEUED, Batch::STATUS_RUNNING], true)) {
            $batch->status = Batch::STATUS_PAUSED;
            $batch->save();
        }

        return $batch;
    }

    public function resume(Batch $batch): Batch
    {
        if (in_array($batch->status, [Batch::STATUS_PAUSED, Batch::STATUS_FAILED], true)) {
            $batch->status = Batch::STATUS_QUEUED;
            $batch->error = null;
            $batch->save();

            $this->queue->push(new ProcessBatchJob($batch->id));
        }

        return $batch;
    }

    public function cancel(Batch $batch): Batch
    {
        if (! in_array($batch->status, [Batch::STATUS_REVERTED, Batch::STATUS_REVERTING], true)) {
            $batch->status = Batch::STATUS_CANCELLED;
            $batch->finished_at = Carbon::now();
            $batch->save();

            $batch->items()->pending()->update(['status' => Item::STATUS_SKIPPED]);
        }

        return $batch;
    }

    /** Re-queues items that used up their attempts, after the cause was fixed. */
    public function retryFailed(Batch $batch): Batch
    {
        $batch->items()
            ->where('status', Item::STATUS_FAILED)
            ->update(['status' => Item::STATUS_PENDING, 'attempts' => 0, 'error' => null]);

        $batch->failed_count = 0;
        $batch->status = Batch::STATUS_QUEUED;
        $batch->error = null;
        $batch->save();

        $this->queue->push(new ProcessBatchJob($batch->id));

        return $batch;
    }

    public function revert(Batch $batch): Batch
    {
        $batch->status = Batch::STATUS_REVERTING;
        $batch->save();

        $this->queue->push(new RevertBatchJob($batch->id));

        return $batch;
    }

    /**
     * Explodes the plan into one row per entity to create. This is what makes
     * the run resumable, pausable and revertible.
     */
    protected function persistItems(Batch $batch, PlanResult $plan): void
    {
        $userRows = [];

        foreach ($plan->users as $index => $user) {
            $userRows[] = [
                'batch_id' => $batch->id,
                'type' => Item::TYPE_USER,
                'scheduled_at' => Dates::toUtc($user['joined_at'])->format('Y-m-d H:i:s'),
                'position' => $index,
                'status' => Item::STATUS_PENDING,
            ];
        }

        $this->insertRows($userRows);
        $userItems = $this->itemIdsByPosition($batch, Item::TYPE_USER);

        $discussionRows = [];

        foreach ($plan->discussions as $index => $discussion) {
            $discussionRows[] = [
                'batch_id' => $batch->id,
                'type' => Item::TYPE_DISCUSSION,
                'scheduled_at' => Dates::toUtc($discussion['created_at'])->format('Y-m-d H:i:s'),
                'position' => $index,
                'author_item_id' => $userItems[$discussion['author']] ?? null,
                'payload' => json_encode([
                    'tag_path' => $discussion['tag_path'],
                    'tag_name' => $discussion['tag_name'],
                ]),
                'status' => Item::STATUS_PENDING,
            ];
        }

        $this->insertRows($discussionRows);
        $discussionItems = $this->itemIdsByPosition($batch, Item::TYPE_DISCUSSION);

        $replyRows = [];

        foreach ($plan->discussions as $index => $discussion) {
            foreach ($discussion['replies'] as $order => $reply) {
                $replyRows[] = [
                    'batch_id' => $batch->id,
                    'type' => Item::TYPE_REPLY,
                    'scheduled_at' => Dates::toUtc($reply['created_at'])->format('Y-m-d H:i:s'),
                    'position' => $order,
                    'parent_item_id' => $discussionItems[$index] ?? null,
                    'author_item_id' => $userItems[$reply['author']] ?? null,
                    'status' => Item::STATUS_PENDING,
                ];
            }
        }

        $this->insertRows($replyRows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function insertRows(array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $this->db->table('ai_seeder_items')->insert($chunk);
        }
    }

    /**
     * @return array<int, int>  plan index => item id
     */
    protected function itemIdsByPosition(Batch $batch, string $type): array
    {
        $map = [];

        $rows = $this->db->table('ai_seeder_items')
            ->where('batch_id', $batch->id)
            ->where('type', $type)
            ->orderBy('position')
            ->get(['id', 'position']);

        foreach ($rows as $row) {
            $map[(int) $row->position] = (int) $row->id;
        }

        return $map;
    }
}
