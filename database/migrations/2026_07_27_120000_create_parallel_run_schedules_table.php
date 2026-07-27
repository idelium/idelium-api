<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parallel_run_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idProject');
            $table->unsignedBigInteger('testCycleId');
            $table->unsignedBigInteger('performedTestCycleId')->nullable();
            $table->unsignedBigInteger('idCostumer');
            $table->string('idempotencyKey', 128);
            $table->string('status', 32)->default('queued');
            $table->unsignedSmallInteger('requestedConcurrency')->default(1);
            $table->unsignedSmallInteger('activeWorkers')->default(0);
            $table->unsignedSmallInteger('totalWorkers')->default(0);
            $table->unsignedSmallInteger('completedWorkers')->default(0);
            $table->unsignedSmallInteger('failedWorkers')->default(0);
            $table->unsignedSmallInteger('cancelledWorkers')->default(0);
            $table->unsignedSmallInteger('aggregateStatus')->nullable();
            $table->json('workerStates')->nullable();
            $table->json('resultSummary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('scheduledAt')->useCurrent();
            $table->timestamp('startedAt')->nullable();
            $table->timestamp('completedAt')->nullable();
            $table->timestamp('cancelledAt')->nullable();
            $table->timestamps();

            $table->unique(
                ['idCostumer', 'idProject', 'idempotencyKey'],
                'parallel_run_schedules_tenant_project_idempotency_unique'
            );
            $table->foreign('idProject')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('testCycleId')->references('id')->on('test_cycles')->cascadeOnDelete();
            $table->foreign('performedTestCycleId')
                ->references('id')
                ->on('performed_test_cycles')
                ->nullOnDelete();
            $table->index(['idCostumer', 'idProject', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parallel_run_schedules');
    }
};
