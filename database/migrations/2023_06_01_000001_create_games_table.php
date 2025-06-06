<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('status', ['waiting', 'playing', 'ended'])->default('waiting');
            $table->unsignedSmallInteger('max_players');
            $table->unsignedSmallInteger('current_players')->default(0);
            $table->json('game_data')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('game_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('waiting');
            $table->integer('score')->default(0);
            $table->json('cards')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'user_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_user');
        Schema::dropIfExists('games');
    }
};
