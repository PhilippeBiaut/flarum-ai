<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('ai_seeder_batches', function (Blueprint $table) {
    $table->increments('id');
    $table->string('status', 20)->default('planned')->index();
    $table->text('config');
    $table->text('plan_summary')->nullable();
    $table->string('model', 100)->nullable();
    $table->unsignedBigInteger('seed')->default(0);

    $table->unsignedInteger('users_planned')->default(0);
    $table->unsignedInteger('discussions_planned')->default(0);
    $table->unsignedInteger('replies_planned')->default(0);

    $table->unsignedInteger('users_created')->default(0);
    $table->unsignedInteger('discussions_created')->default(0);
    $table->unsignedInteger('replies_created')->default(0);
    $table->unsignedInteger('failed_count')->default(0);

    $table->unsignedBigInteger('tokens_in')->default(0);
    $table->unsignedBigInteger('tokens_out')->default(0);
    $table->unsignedInteger('api_calls')->default(0);

    $table->text('error')->nullable();
    $table->dateTime('created_at');
    $table->dateTime('started_at')->nullable();
    $table->dateTime('finished_at')->nullable();
});
