<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->string('agentId', 128);
            $table->string('status', 32);
            $table->string('version', 64)->nullable();
            $table->json('runtimes')->nullable();
            $table->json('capabilities')->nullable();
            $table->unsignedSmallInteger('maxConcurrency')->default(1);
            $table->string('health', 32)->default('unknown');
            $table->timestamp('lastSeenAt')->nullable();
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->unique(['idCostumer', 'agentId'], 'agent_registrations_tenant_agent_unique');
            $table->index(['idCostumer', 'status', 'health']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_registrations');
    }
};
