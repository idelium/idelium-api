<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actorUserId')->nullable();
            $table->unsignedBigInteger('actorTenantId')->nullable();
            $table->unsignedBigInteger('activeTenantId');
            $table->unsignedBigInteger('idProject')->nullable();
            $table->string('action', 128);
            $table->string('targetType', 128);
            $table->string('targetId', 128)->nullable();
            $table->json('beforeValues')->nullable();
            $table->json('afterValues')->nullable();
            $table->string('result', 32);
            $table->string('sourceIp', 64)->nullable();
            $table->uuid('correlationId');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('actorUserId')->references('id')->on('users')->nullOnDelete();
            $table->foreign('actorTenantId')->references('id')->on('costumers')->nullOnDelete();
            $table->foreign('activeTenantId')->references('id')->on('costumers')->restrictOnDelete();
            $table->foreign('idProject')->references('id')->on('projects')->nullOnDelete();
            $table->index(['activeTenantId', 'action', 'created_at']);
            $table->index(['activeTenantId', 'targetType', 'targetId']);
            $table->index(['activeTenantId', 'correlationId']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
