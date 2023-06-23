<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePerformedStepsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('performed_steps', function (Blueprint $table) {
            $table->id();
            $table->integer('testCycleDoneId');
            $table->integer('testDoneId');
            $table->integer('stepId');
            $table->integer('status');
            $table->string('name');
            $table->json('screenshots');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('performed_steps');
    }
}
