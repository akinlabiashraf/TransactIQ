<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique(); // e.g. REF-20261007-XXXXXXXX
            $table->foreignUuid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->unsignedBigInteger('amount'); // Minor currency units (kobo/cents)
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('COMPLETED'); // PENDING, COMPLETED, FAILED
            $table->string('reason')->default('CUSTOMER_REQUEST');
            $table->string('gateway_refund_reference')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index('transaction_id');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
