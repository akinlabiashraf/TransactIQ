<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique(); // e.g. TXN-20260921-000001
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUuid('settlement_id')->nullable()->constrained('settlements')->nullOnDelete();
            $table->string('idempotency_key')->nullable();
            
            // Financials in minor units (kobo/cents) to prevent floating point inaccuracies
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->string('currency', 3)->default('NGN');

            // Finite State Machine: INITIATED, PROCESSING, SUCCESS, FAILED, PENDING, REVERSED
            $table->string('status')->default('INITIATED');
            $table->string('payment_method')->default('CARD'); // CARD, BANK_TRANSFER, USSD
            $table->string('channel')->default('API');         // API, CHECKOUT_PAGE, POS
            
            $table->string('provider')->nullable();            // SIMULATED_GATEWAY, PROVIDER_X
            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();
            
            $table->jsonb('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // Indexes for high-throughput queries and idempotency enforcement
            $table->unique(['merchant_id', 'idempotency_key'], 'unique_merchant_idempotency');
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'created_at']);
            $table->index('reference');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
