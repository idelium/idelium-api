<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_providers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->string('type', 32);
            $table->string('name', 128);
            $table->string('issuer', 512)->nullable();
            $table->string('audience', 512)->nullable();
            $table->json('redirectUris')->nullable();
            $table->json('groupRoleMap')->nullable();
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->unique(['idCostumer', 'type', 'name'], 'identity_provider_name_unique');
            $table->index(['idCostumer', 'type', 'status'], 'identity_provider_scope_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_providers');
    }
};
