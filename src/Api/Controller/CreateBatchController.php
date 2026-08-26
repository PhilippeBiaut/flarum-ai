<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Pbiaut\AiSeeder\Api\BatchPresenter;
use Pbiaut\AiSeeder\Service\BatchService;
use Psr\Http\Message\ServerRequestInterface;

class CreateBatchController extends AbstractSeederController
{
    public function __construct(
        protected BatchService $batches,
        protected BatchPresenter $presenter,
    ) {
    }

    protected function data(ServerRequestInterface $request): array
    {
        $batch = $this->batches->create($this->body($request));

        return ['batch' => $this->presenter->present($batch, true)];
    }
}
