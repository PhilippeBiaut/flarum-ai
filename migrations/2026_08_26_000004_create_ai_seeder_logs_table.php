<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('ai_seeder_logs', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('batch_id');
    $table->string('level', 10)->default('info');
    $table->text('message');
    $table->dateTime('created_at');

    $table->index(['batch_id', 'id']);

    $table->foreign('batch_id')
        ->references('id')
        ->on('ai_seeder_batches')
        ->onDelete('cascade');
});
