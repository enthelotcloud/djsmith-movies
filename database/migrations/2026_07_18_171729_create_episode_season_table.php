<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episode_season', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Prevent duplicate pivot entries
            $table->unique(['episode_id', 'season_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_season');
    }
};
