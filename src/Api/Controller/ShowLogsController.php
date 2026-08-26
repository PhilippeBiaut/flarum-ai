<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Illuminate\Support\Arr;
use Pbiaut\AiSeeder\Service\RunLogger;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The run log for one batch. Polled with the id of the last line already shown,
 * so the admin screen only ever fetches what is new.
 */
class ShowLogsController extends AbstractSeederController
{
    public function __construct(protected RunLogger $logs)
    {
    }

    protected function data(ServerRequestInterface $request): array
    {
        $batch = $this->findBatch($request);
        $after = (int) Arr::get($request->getQueryParams(), 'after', 0);

        return ['logs' => $this->logs->since($batch->id, $after)];
    }
}
