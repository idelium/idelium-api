<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('idProject');
            $table->string('name', 128);
            $table->string('adapter', 32);
            $table->string('url', 2048);
            $table->text('secretEncrypted');
            $table->json('events')->nullable();
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->foreign('idProject')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['idCostumer', 'idProject', 'name'], 'integration_endpoint_name_unique');
            $table->index(['idCostumer', 'idProject', 'status'], 'integration_endpoint_scope_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_endpoints');
    }
};
