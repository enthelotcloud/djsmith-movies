<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('description')->nullable();
            $table->string('thumbnail')->nullable(); // or thumbnail_path
            $table->string('video_path')->nullable();
            $table->integer('duration_in_seconds')->nullable();
            $table->boolean('is_premium')->default(false);
            $table->string('status')->default('ready'); // processing, ready, etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
