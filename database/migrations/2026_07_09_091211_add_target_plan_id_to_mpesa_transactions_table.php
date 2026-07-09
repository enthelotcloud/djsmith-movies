<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            // Track if this top-up was meant to automatically buy a plan
            $table->foreignId('target_plan_id')->nullable()->after('user_id')->constrained('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropForeign(['target_plan_id']);
            $table->dropColumn('target_plan_id');
        });
    }
};