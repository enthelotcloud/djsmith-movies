<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('movies')->cascadeOnDelete();
            $table->integer('season_number');
            $table->string('title')->nullable();
            $table->string('poster_path')->nullable();
            $table->timestamps();

            $table->unique(['movie_id', 'season_number']);
        });

        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->integer('episode_number');
            $table->string('title');
            $table->text('description')->nullable();

            // Backblaze B2 Storage Info for the actual episode video file
            $table->string('video_disk')->default('b2');
            $table->string('video_path');
            $table->string('thumbnail_path')->nullable();
            $table->integer('duration_in_seconds')->nullable();

            $table->timestamps();

            $table->unique(['season_id', 'episode_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
        Schema::dropIfExists('seasons');
    }
};
