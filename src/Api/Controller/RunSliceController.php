<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Pbiaut\AiSeeder\Api\BatchPresenter;
use Pbiaut\AiSeeder\Service\QueueInspector;
use Pbiaut\AiSeeder\Service\RunLogger;
use Pbiaut\AiSeeder\Service\SliceRunner;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Runs one slice of a batch and returns. The admin screen calls this in a loop
 * on forums without a queue worker.
 *
 * Any failure is written to the run log before being returned, so the reason
 * survives even if the browser tab is closed mid-run.
 */
class RunSliceController extends AbstractSeederController
{
    public function __construct(
        protected SliceRunner $slices,
        protected BatchPresenter $presenter,
        protected QueueInspector $queues,
        protected RunLogger $logs,
    ) {
    }

    protected function data(ServerRequestInterface $request): array
    {
        $batch = $this->findBatch($request);

        try {
            $more = $this->slices->run($batch);
        } catch (Throwable $e) {
            $this->logs->error($batch->id, $e->getMessage());

            throw $e;
        }

        $batch->refresh();

        return [
            'batch' => $this->presenter->present($batch, true),
            'more' => $more,
            'queue' => $this->queues->describe(),
        ];
    }
}
