<?php

namespace Pbiaut\AiSeeder\Job;

use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Queue\Queue;
use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\Service\QueueInspector;
use Pbiaut\AiSeeder\Service\RevertRunner;

/**
 * Deletes everything a batch created, one slice at a time.
 */
class RevertBatchJob extends AbstractJob
{
    public function __construct(public int $batchId)
    {
        parent::__construct();
    }

    public function handle(RevertRunner $runner, Queue $queue, QueueInspector $queues): void
    {
        $batch = Batch::find($this->batchId);

        if ($batch === null) {
            return;
        }

        // Under the sync driver a re-queue runs inline, which would recurse
        // until PHP times out; the admin screen drives the slices there.
        if ($runner->run($batch) && ! $queues->isSync()) {
            $queue->push(new self($this->batchId));
        }
    }
}
