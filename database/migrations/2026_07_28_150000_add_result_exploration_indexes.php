<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performed_test_cycles', function (Blueprint $table) {
            $table->index(
                ['idCostumer', 'testCycleId', 'status', 'date'],
                'performed_cycles_exploration_idx'
            );
        });

        Schema::table('performed_tests', function (Blueprint $table) {
            $table->index(
                ['idCostumer', 'testCycleDoneId', 'status', 'id'],
                'performed_tests_exploration_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('performed_tests', function (Blueprint $table) {
            $table->dropIndex('performed_tests_exploration_idx');
        });

        Schema::table('performed_test_cycles', function (Blueprint $table) {
            $table->dropIndex('performed_cycles_exploration_idx');
        });
    }
};
