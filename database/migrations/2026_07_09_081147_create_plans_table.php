<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "5-Minute Snoop", "Monthly Premium"
            $table->decimal('price', 10, 2);
            $table->integer('duration_minutes'); // Minutes allows extreme flexibility (5 mins, or 43200 for 30 days)
            $table->boolean('can_download')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};