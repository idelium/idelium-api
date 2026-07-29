<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('idProject');
            $table->unsignedBigInteger('parallelRunScheduleId');
            $table->string('agentId', 128);
            $table->string('tokenId', 64)->unique();
            $table->string('tokenHash');
            $table->timestamp('expiresAt');
            $table->timestamp('usedAt')->nullable();
            $table->timestamp('revokedAt')->nullable();
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->foreign('idProject')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('parallelRunScheduleId')
                ->references('id')
                ->on('parallel_run_schedules')
                ->cascadeOnDelete();
            $table->index(['idCostumer', 'idProject', 'parallelRunScheduleId', 'agentId'], 'run_token_scope_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_tokens');
    }
};
