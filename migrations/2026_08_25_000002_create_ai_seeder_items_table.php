<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('ai_seeder_items', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('batch_id');
    $table->string('type', 12);
    $table->dateTime('scheduled_at');
    $table->unsignedInteger('parent_item_id')->nullable();
    $table->unsignedInteger('author_item_id')->nullable();
    $table->unsignedInteger('target_id')->nullable();
    $table->unsignedInteger('position')->default(0);
    $table->text('payload')->nullable();
    $table->string('status', 12)->default('pending');
    $table->unsignedSmallInteger('attempts')->default(0);
    $table->text('error')->nullable();

    $table->index(['batch_id', 'type', 'status']);
    $table->index(['batch_id', 'target_id']);
    $table->index(['parent_item_id']);

    $table->foreign('batch_id')
        ->references('id')
        ->on('ai_seeder_batches')
        ->onDelete('cascade');
});
