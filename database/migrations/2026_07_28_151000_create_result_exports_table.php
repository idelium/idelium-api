<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idCostumer');
            $table->unsignedBigInteger('performedTestCycleId');
            $table->string('format', 32);
            $table->string('status', 32);
            $table->string('filename', 255);
            $table->string('contentType', 128);
            $table->longText('payload')->nullable();
            $table->text('errorMessage')->nullable();
            $table->timestamp('expiresAt');
            $table->timestamps();

            $table->foreign('idCostumer')->references('id')->on('costumers')->restrictOnDelete();
            $table->foreign('performedTestCycleId')
                ->references('id')
                ->on('performed_test_cycles')
                ->cascadeOnDelete();
            $table->index(['idCostumer', 'performedTestCycleId', 'status']);
            $table->index(['idCostumer', 'expiresAt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_exports');
    }
};
