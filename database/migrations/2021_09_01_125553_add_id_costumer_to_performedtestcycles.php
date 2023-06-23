<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIdCostumerToPerformedtestcycles extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('performed_test_cycles', function (Blueprint $table) {
            $table->integer('idCostumer');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('performed_test_cycles', function (Blueprint $table) {
            $table->dropColumn('idCostumer');
        });
    }
}
