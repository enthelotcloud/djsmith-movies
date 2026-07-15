<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movies', function (Blueprint $table) {
            $table->id();

            // Series Architecture
            $table->enum('type', ['movie', 'series'])->default('movie');

            // Meta Data
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('excerpt')->nullable();
            $table->text('description')->nullable();

            // Backblaze B2 Storage Info (Nullable because Series don't use this directly)
            $table->string('video_disk')->nullable()->default('b2');
            $table->string('video_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->integer('duration_in_seconds')->nullable();

            // Monetization & Stats
            $table->boolean('is_premium')->default(true);
            $table->decimal('rating', 3, 1)->default(5.0);
            $table->unsignedBigInteger('views')->default(0);
            $table->decimal('pay_per_view_price', 8, 2)->nullable();

            // Processing State
            $table->enum('status', ['uploading', 'processing', 'ready', 'hidden'])->default('uploading');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movies');
    }
};
