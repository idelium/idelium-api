<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('idProject');
            $table->string('assetType', 64);
            $table->unsignedBigInteger('assetId');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('actorUserId')->nullable();
            $table->string('reason', 255);
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->foreign('idProject')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('actorUserId')->references('id')->on('users')->nullOnDelete();
            $table->unique(['idCostumer', 'assetType', 'assetId', 'version'], 'asset_versions_unique_version');
            $table->index(['idCostumer', 'idProject', 'assetType', 'assetId']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_versions');
    }
};
