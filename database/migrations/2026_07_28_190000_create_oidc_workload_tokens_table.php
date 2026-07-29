<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_workload_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('idProject');
            $table->string('provider', 64);
            $table->string('subject', 512);
            $table->string('repository', 256)->nullable();
            $table->string('ref', 512)->nullable();
            $table->string('environment', 128)->nullable();
            $table->string('tokenId', 64)->unique();
            $table->string('tokenHash');
            $table->json('scopes')->nullable();
            $table->timestamp('expiresAt');
            $table->timestamp('revokedAt')->nullable();
            $table->timestamp('lastUsedAt')->nullable();
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->foreign('idProject')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['idCostumer', 'idProject', 'provider', 'expiresAt'], 'oidc_token_scope_provider_expiry_idx');
        });

        Schema::create('oidc_workload_assertions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('jti', 512);
            $table->timestamp('expiresAt');
            $table->timestamps();

            $table->unique(['provider', 'jti'], 'oidc_workload_assertions_provider_jti_unique');
            $table->index(['expiresAt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_workload_assertions');
        Schema::dropIfExists('oidc_workload_tokens');
    }
};
