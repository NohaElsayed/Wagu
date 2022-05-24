<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOpenpointsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('openpoints', function (Blueprint $table) {
            $table->id();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->string('break')->nullable();
            $table->string('end_Shift');
            $table->string('start_Shift');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            // $table->foreign('delivery_man_id')->references('id')->on('sections')->onDelete('delivery_men');

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
        Schema::dropIfExists('openpoints');
    }
}
