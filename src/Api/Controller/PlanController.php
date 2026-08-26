<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Pbiaut\AiSeeder\Service\BatchService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dry run: returns the whole day-by-day calendar and the cost estimate.
 * Costs nothing and touches nothing.
 */
class PlanController extends AbstractSeederController
{
    public function __construct(protected BatchService $batches)
    {
    }

    protected function data(ServerRequestInterface $request): array
    {
        return $this->batches->preview($this->body($request));
    }
}
