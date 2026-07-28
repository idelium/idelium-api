<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_version_review_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('idProject');
            $table->unsignedBigInteger('assetVersionId');
            $table->string('fromStatus', 32);
            $table->string('toStatus', 32);
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('actorUserId')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->foreign('idProject')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('assetVersionId')->references('id')->on('asset_versions')->cascadeOnDelete();
            $table->foreign('actorUserId')->references('id')->on('users')->nullOnDelete();
            $table->index(['idCostumer', 'idProject', 'assetVersionId', 'created_at'], 'asset_review_tenant_project_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_version_review_events');
    }
};
