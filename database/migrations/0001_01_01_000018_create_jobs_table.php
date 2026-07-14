<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Standard Laravel 'jobs' table — required by the 'database' queue
 * connection (config/queue.php) to store pending jobs such as
 * SendTransferNotification (see TransferController::store()).
 *
 * This was missing even though failed_jobs and job_batches were both
 * migrated — without it, dispatching any job on the 'database' driver
 * throws a "table 'jobs' doesn't exist" error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
