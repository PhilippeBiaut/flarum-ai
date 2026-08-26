<?php

/*
 * This file is part of pbiaut/flarum-ai-seeder.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Pbiaut\AiSeeder;

use Flarum\Extend;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/ai-seeder/models', 'ai-seeder.models', Api\Controller\ListModelsController::class)
        ->post('/ai-seeder/plan', 'ai-seeder.plan', Api\Controller\PlanController::class)
        ->get('/ai-seeder/batches', 'ai-seeder.batches.index', Api\Controller\ListBatchesController::class)
        ->post('/ai-seeder/batches', 'ai-seeder.batches.create', Api\Controller\CreateBatchController::class)
        ->get('/ai-seeder/batches/{id}', 'ai-seeder.batches.show', Api\Controller\ShowBatchController::class)
        ->get('/ai-seeder/batches/{id}/logs', 'ai-seeder.batches.logs', Api\Controller\ShowLogsController::class)
        ->post('/ai-seeder/batches/{id}/run', 'ai-seeder.batches.run', Api\Controller\RunSliceController::class)
        ->post('/ai-seeder/batches/{id}/state', 'ai-seeder.batches.state', Api\Controller\UpdateBatchStateController::class)
        ->delete('/ai-seeder/batches/{id}', 'ai-seeder.batches.delete', Api\Controller\RevertBatchController::class),

    // Jobs deliberately stay on the default queue: routing them to a named one
    // would silently do nothing unless the admin remembered to start the worker
    // with a matching --queue flag. Runs are sliced into short jobs anyway, so
    // they interleave with the forum's own mail jobs instead of blocking them.

    (new Extend\Console())
        ->command(Console\SeedCommand::class),
];
