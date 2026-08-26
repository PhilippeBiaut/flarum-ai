<?php

namespace Pbiaut\AiSeeder\Api;

use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\Model\Item;
use Pbiaut\AiSeeder\Service\CostEstimator;

/**
 * Shapes a batch for the admin UI, including live progress.
 */
class BatchPresenter
{
    public function __construct(protected CostEstimator $estimator)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Batch $batch, bool $withPlan = false): array
    {
        $data = [
            'id' => $batch->id,
            'mode' => $batch->mode ?: Batch::MODE_GENERATE,
            'status' => $batch->status,
            'model' => $batch->model,
            'seed' => $batch->seed,
            'progress' => $batch->progress(),
            'planned' => [
                'users' => (int) $batch->users_planned,
                'discussions' => (int) $batch->discussions_planned,
                'replies' => (int) $batch->replies_planned,
            ],
            'created' => [
                'users' => (int) $batch->users_created,
                'discussions' => (int) $batch->discussions_created,
                'replies' => (int) $batch->replies_created,
            ],
            'failed' => (int) $batch->failed_count,
            'pending' => $batch->items()->where('status', Item::STATUS_PENDING)->count(),
            // Cast rather than trust: a batch presented straight after create()
            // has never been read back from the database.
            'usage' => [
                'tokens_in' => (int) $batch->tokens_in,
                'tokens_out' => (int) $batch->tokens_out,
                'api_calls' => (int) $batch->api_calls,
            ],
            'cost' => $this->estimator->actual((int) $batch->tokens_in, (int) $batch->tokens_out),
            'error' => $batch->error,
            'period' => [
                'start' => $batch->config['date_start'] ?? null,
                'end' => $batch->config['date_end'] ?? null,
            ],
            'created_at' => $batch->created_at?->toIso8601String(),
            'started_at' => $batch->started_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
        ];

        if ($withPlan) {
            $data['plan'] = $batch->plan_summary;
            $data['config'] = $this->safeConfig($batch);
            $data['recent_failures'] = $this->recentFailures($batch);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function safeConfig(Batch $batch): array
    {
        $config = $batch->config ?? [];
        unset($config['api_key']);

        return $config;
    }

    /**
     * @return array<int, array{type: string, error: string}>
     */
    protected function recentFailures(Batch $batch): array
    {
        return $batch->items()
            ->where('status', Item::STATUS_FAILED)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Item $item) => [
                'type' => $item->type,
                'error' => (string) $item->error,
            ])
            ->all();
    }
}
