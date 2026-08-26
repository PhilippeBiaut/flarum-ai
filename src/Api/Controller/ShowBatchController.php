<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Pbiaut\AiSeeder\Api\BatchPresenter;
use Pbiaut\AiSeeder\Service\QueueInspector;
use Psr\Http\Message\ServerRequestInterface;

/** Polled by the admin screen while a run is in progress. */
class ShowBatchController extends AbstractSeederController
{
    public function __construct(
        protected BatchPresenter $presenter,
        protected QueueInspector $queues,
    ) {
    }

    protected function data(ServerRequestInterface $request): array
    {
        return [
            'batch' => $this->presenter->present($this->findBatch($request), true),
            'queue' => $this->queues->describe(),
        ];
    }
}
