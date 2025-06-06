<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('game_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->unsignedTinyInteger('max_players');
            $table->enum('type', ['public', 'private'])->default('public');
            $table->enum('status', ['waiting', 'playing', 'ended'])->default('waiting');
            $table->json('players')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('game_rooms');
    }
};
