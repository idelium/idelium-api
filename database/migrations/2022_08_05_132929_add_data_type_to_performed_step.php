<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDataTypeToPerformedStep extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('performed_steps', function (Blueprint $table) {
            $table->string('type');
            $table->json('data');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('performed_steps', function (Blueprint $table) {
            $table->dropColumn('type');
            $table->dropColumn('data');
        });
    }
}
