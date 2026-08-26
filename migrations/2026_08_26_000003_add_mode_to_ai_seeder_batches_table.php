<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A batch either generates new content or tags discussions that already exist.
 * Both share the same progress, pause and rollback machinery, so they share the
 * same table and are told apart by this column.
 */
return Migration::addColumns('ai_seeder_batches', [
    'mode' => ['string', 'length' => 20, 'default' => 'generate'],
]);
