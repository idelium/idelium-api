<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status', 32)->default('active')->after('idCostumer');
            $table->boolean('mfaRequired')->default(false)->after('status');
            $table->boolean('isBreakGlass')->default(false)->after('mfaRequired');
            $table->string('breakGlassReason', 512)->nullable()->after('isBreakGlass');
            $table->timestamp('lastBreakGlassTestAt')->nullable()->after('breakGlassReason');
            $table->unsignedBigInteger('identityProviderId')->nullable()->after('lastBreakGlassTestAt');
            $table->string('externalId', 256)->nullable()->after('identityProviderId');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'status',
                'mfaRequired',
                'isBreakGlass',
                'breakGlassReason',
                'lastBreakGlassTestAt',
                'identityProviderId',
                'externalId',
            ]);
        });
    }
};
