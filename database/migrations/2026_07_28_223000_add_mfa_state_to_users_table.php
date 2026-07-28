<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('mfaSecretEncrypted')->nullable()->after('mfaRequired');
            $table->timestamp('mfaConfirmedAt')->nullable()->after('mfaSecretEncrypted');
            $table->json('mfaRecoveryCodeHashes')->nullable()->after('mfaConfirmedAt');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'mfaSecretEncrypted',
                'mfaConfirmedAt',
                'mfaRecoveryCodeHashes',
            ]);
        });
    }
};
