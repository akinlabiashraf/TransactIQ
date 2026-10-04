<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('settlement_reference')->unique(); // e.g. SETTLE-20260921-0001
            $table->unsignedBigInteger('gross_amount');       // minor units (kobo/cents)
            $table->unsignedBigInteger('fee_amount');         // platform deductions
            $table->unsignedBigInteger('net_amount');         // net payout to merchant
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('PENDING');     // PENDING, PROCESSING, COMPLETED, FAILED
            $table->unsignedInteger('transaction_count')->default(0);
            $table->string('payout_channel')->default('BANK_TRANSFER');
            $table->string('payout_reference')->nullable();
            $table->timestamp('payout_date')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
