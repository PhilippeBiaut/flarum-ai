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
        $planConfig = PlanConfig::fromArray($config, $this->settings->timezone());
        $plan = $this->planner->plan($planConfig);

        return array_merge($plan->toSummaryArray(), [
            'config' => $planConfig->toArray(),
            'estimate' => $this->estimator->estimate($planConfig, $plan),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function create(array $config): Batch
    {
        $planConfig = PlanConfig::fromArray($config, $this->settings->timezone());
        $plan = $this->planner->plan($planConfig);

        $batch = $this->db->transaction(function () use ($planConfig, $plan) {
            $batch = new Batch();
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
