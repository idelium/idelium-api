<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('idProject')->nullable();
            $table->string('name', 128);
            $table->string('credentialId', 64)->unique();
            $table->string('secretHash');
            $table->json('scopes')->nullable();
            $table->timestamp('expiresAt')->nullable();
            $table->timestamp('revokedAt')->nullable();
            $table->timestamp('lastUsedAt')->nullable();
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->foreign('idProject')->references('id')->on('projects')->nullOnDelete();
            $table->index(['idCostumer', 'idProject', 'revokedAt', 'expiresAt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_accounts');
    }
};
