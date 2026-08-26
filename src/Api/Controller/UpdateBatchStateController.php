<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Pbiaut\AiSeeder\Api\BatchPresenter;
use Pbiaut\AiSeeder\Planner\InvalidConfigException;
use Pbiaut\AiSeeder\Service\BatchService;
use Psr\Http\Message\ServerRequestInterface;

class UpdateBatchStateController extends AbstractSeederController
{
    public function __construct(
        protected BatchService $batches,
        protected BatchPresenter $presenter,
    ) {
    }

    protected function data(ServerRequestInterface $request): array
    {
        $batch = $this->findBatch($request);
        $action = (string) ($this->body($request)['action'] ?? '');

        $batch = match ($action) {
            'pause' => $this->batches->pause($batch),
            'resume' => $this->batches->resume($batch),
            'cancel' => $this->batches->cancel($batch),
            'retry-failed' => $this->batches->retryFailed($batch),
            default => throw new InvalidConfigException([
                'action' => 'must be one of pause, resume, cancel, retry-failed',
            ]),
        };

        return ['batch' => $this->presenter->present($batch, true)];
    }
}
