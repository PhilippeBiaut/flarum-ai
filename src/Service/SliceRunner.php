<?php

namespace Pbiaut\AiSeeder\Service;

use Pbiaut\AiSeeder\Model\Batch;

/**
 * Runs exactly one slice of a batch, synchronously.
 *
 * This is what the admin screen calls in a loop on a forum with no queue
 * worker: each request does a bounded amount of work and returns, so nothing
 * ever approaches PHP's execution limit, and progress is visible between
 * slices instead of after a timeout.
 */
class SliceRunner
{
    public function __construct(
        protected BatchRunner $generation,
        protected TaggingRunner $tagging,
        protected RevertRunner $revert,
    ) {
    }

    /**
     * @return bool  true when work remains
     */
    public function run(Batch $batch): bool
    {
        if (in_array($batch->status, [Batch::STATUS_REVERTING, Batch::STATUS_REVERTED], true)) {
            return $batch->status === Batch::STATUS_REVERTED ? false : $this->revert->run($batch);
        }

        if ($batch->isHalted()) {
            return false;
        }

        return $batch->isTagging()
            ? $this->tagging->run($batch)
            : $this->generation->run($batch);
    }
}
