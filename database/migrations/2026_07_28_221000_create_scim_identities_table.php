<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scim_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('identityProviderId');
            $table->unsignedBigInteger('userId')->nullable();
            $table->string('externalId', 256);
            $table->string('userName', 320);
            $table->json('groups')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('lastSyncedAt')->nullable();
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->foreign('identityProviderId')->references('id')->on('identity_providers')->cascadeOnDelete();
            $table->foreign('userId')->references('id')->on('users')->nullOnDelete();
            $table->unique(['idCostumer', 'identityProviderId', 'externalId'], 'scim_identity_external_unique');
            $table->index(['idCostumer', 'userName'], 'scim_identity_username_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scim_identities');
    }
};
