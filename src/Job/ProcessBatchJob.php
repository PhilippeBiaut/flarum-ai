<?php

namespace Pbiaut\AiSeeder\Job;

use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Queue\Queue;
use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\Service\BatchRunner;

/**
 * Processes one slice of a batch and re-queues itself while work remains.
 *
 * Self-requeueing rather than looping inside a single job keeps every job short
 * (a dozen API calls), so a worker timeout can never lose a whole run and
 * pause / cancel take effect within a minute or two.
 */
class ProcessBatchJob extends AbstractJob
{
    public function __construct(public int $batchId)
    {
        parent::__construct();
    }

    public function handle(BatchRunner $runner, Queue $queue): void
    {
        $batch = Batch::find($this->batchId);

        if ($batch === null) {
            return;
        }

        if ($runner->run($batch)) {
            // A small delay between slices keeps a rate-limited or failing run
            // from spinning through the queue as fast as the worker can go.
            $queue->later($runner->retryAfter, new self($this->batchId));
        }
    }
}
