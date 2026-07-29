<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('archivedAt')->nullable()->after('description');
            $table->json('tags')->nullable()->after('archivedAt');
            $table->index(['idCostumer', 'archivedAt']);
        });

        Schema::create('grid_query_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('actorUserId');
            $table->string('resourceType', 64);
            $table->json('query');
            $table->json('entityIds');
            $table->unsignedInteger('total');
            $table->timestamp('expiresAt');
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->foreign('actorUserId')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['idCostumer', 'resourceType', 'expiresAt']);
        });

        Schema::create('grid_bulk_operation_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('querySnapshotId');
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('actorUserId');
            $table->string('resourceType', 64);
            $table->string('action', 32);
            $table->string('status', 32);
            $table->json('payload')->nullable();
            $table->unsignedInteger('requestedCount');
            $table->unsignedInteger('processedCount')->default(0);
            $table->unsignedInteger('failedCount')->default(0);
            $table->json('result')->nullable();
            $table->timestamps();

            $table->foreign('querySnapshotId')->references('id')->on('grid_query_snapshots')->cascadeOnDelete();
            $table->foreign('idCostumer')->references('id')->on('costumers')->cascadeOnDelete();
            $table->foreign('actorUserId')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['idCostumer', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_bulk_operation_jobs');
        Schema::dropIfExists('grid_query_snapshots');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['idCostumer', 'archivedAt']);
            $table->dropColumn(['archivedAt', 'tags']);
        });
    }
};
