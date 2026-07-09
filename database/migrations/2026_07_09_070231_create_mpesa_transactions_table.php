<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_request_id')->index();
            $table->string('checkout_request_id')->index();
            $table->decimal('amount', 10, 2);
            $table->string('phone');
            $table->string('mpesa_receipt_number')->nullable();
            $table->string('status')->default('pending'); // pending, completed, failed
            $table->text('result_desc')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_transactions');
    }
};