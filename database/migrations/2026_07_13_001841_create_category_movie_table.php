<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_movie', function (Blueprint $table) {
            $table->foreignId('movie_id')->constrained('movies')->cascadeOnDelete();
            $table->foreignId('moviecategory_id')->constrained('moviecategories')->cascadeOnDelete();

            // Prevents duplicate tags on the same movie
            $table->primary(['movie_id', 'moviecategory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_movie');
    }
};
