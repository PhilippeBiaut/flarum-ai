<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Pbiaut\AiSeeder\Api\BatchPresenter;
use Pbiaut\AiSeeder\Service\BatchService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Queues the removal of everything this batch created. Nothing is deleted
 * synchronously, so a large rollback cannot time out a browser request.
 */
class RevertBatchController extends AbstractSeederController
{
    public function __construct(
        protected BatchService $batches,
        protected BatchPresenter $presenter,
    ) {
    }

    protected function data(ServerRequestInterface $request): array
    {
        $batch = $this->batches->revert($this->findBatch($request));

        return ['batch' => $this->presenter->present($batch, true)];
    }
}
