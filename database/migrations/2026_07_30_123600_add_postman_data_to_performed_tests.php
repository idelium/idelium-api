<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('performed_tests', 'postmanData')) {
            return;
        }

        Schema::table('performed_tests', function (Blueprint $table) {
            $table->longText('postmanData')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('performed_tests', 'postmanData')) {
            return;
        }

        Schema::table('performed_tests', function (Blueprint $table) {
            $table->dropColumn('postmanData');
        });
    }
};
