<?php

namespace Pbiaut\AiSeeder\Service;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;

/**
 * Tells whether the forum has a real queue behind it.
 *
 * This matters more than it looks. Flarum's default driver is `sync`, whose
 * `later()` does not delay anything: it runs the job inline, immediately. A job
 * that re-queues itself therefore recurses inside the same HTTP request until
 * PHP's max_execution_time kills it - which looks, from the admin panel, like
 * generation dying after one slice with a generic error.
 *
 * So under `sync` nothing is queued at all: the admin screen drives the run one
 * slice per request instead, which keeps every request short and the progress
 * honest.
 */
class QueueInspector
{
    public function __construct(protected Queue $queue)
    {
    }

    public function isSync(): bool
    {
        return $this->queue instanceof SyncQueue;
    }

    public function driver(): string
    {
        return $this->isSync() ? 'sync' : 'worker';
    }

    /**
     * @return array{driver: string, needs_manual_run: bool}
     */
    public function describe(): array
    {
        return [
            'driver' => $this->driver(),
            'needs_manual_run' => $this->isSync(),
        ];
    }
}
