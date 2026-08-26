<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Pbiaut\AiSeeder\Api\BatchPresenter;
use Pbiaut\AiSeeder\Model\Batch;
use Psr\Http\Message\ServerRequestInterface;

class ListBatchesController extends AbstractSeederController
{
    public function __construct(protected BatchPresenter $presenter)
    {
    }

    protected function data(ServerRequestInterface $request): array
    {
        $batches = Batch::query()
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Batch $batch) => $this->presenter->present($batch))
            ->all();

        return ['batches' => $batches];
    }
}
