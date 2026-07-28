<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('idProject');
            $table->unsignedBigInteger('integrationEndpointId');
            $table->string('event', 128);
            $table->string('deliveryId', 96)->unique();
            $table->string('idempotencyKey', 160);
            $table->string('schemaVersion', 64);
            $table->string('payloadDigest', 64);
            $table->string('status', 32);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('responseStatus')->nullable();
            $table->text('lastError')->nullable();
            $table->timestamp('nextAttemptAt')->nullable();
            $table->timestamp('sentAt')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->foreign('idProject')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('integrationEndpointId')
                ->references('id')
                ->on('integration_endpoints')
                ->cascadeOnDelete();
            $table->unique(['idCostumer', 'idProject', 'integrationEndpointId', 'idempotencyKey'], 'integration_delivery_idempotency_unique');
            $table->index(['idCostumer', 'idProject', 'status'], 'integration_delivery_scope_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_deliveries');
    }
};
