<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifact_descriptors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('idProject');
            $table->unsignedBigInteger('performedTestCycleId');
            $table->unsignedBigInteger('performedTestId')->nullable();
            $table->unsignedBigInteger('performedStepId')->nullable();
            $table->string('artifactType', 64);
            $table->string('name', 255);
            $table->string('contentType', 128);
            $table->unsignedBigInteger('sizeBytes');
            $table->string('checksumSha256', 64);
            $table->string('storageKey', 512);
            $table->string('state', 32)->default('available');
            $table->timestamp('retentionUntil')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->restrictOnDelete();
            $table->foreign('idProject')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('performedTestCycleId')
                ->references('id')
                ->on('performed_test_cycles')
                ->cascadeOnDelete();
            $table->foreign('performedTestId')->references('id')->on('performed_tests')->nullOnDelete();
            $table->foreign('performedStepId')->references('id')->on('performed_steps')->nullOnDelete();
            $table->unique(['idCostumer', 'checksumSha256', 'storageKey'], 'artifact_descriptors_tenant_checksum_storage_unique');
            $table->index(['idCostumer', 'idProject', 'performedTestCycleId'], 'artifact_scope_run_idx');
            $table->index(['idCostumer', 'state', 'retentionUntil'], 'artifact_state_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_descriptors');
    }
};
