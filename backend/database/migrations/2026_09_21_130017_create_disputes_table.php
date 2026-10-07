<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique(); // e.g. DSP-20261007-XXXXXXXX
            $table->foreignUuid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->unsignedBigInteger('amount'); // Disputed amount in minor units
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('OPEN'); // OPEN, UNDER_REVIEW, WON, LOST
            $table->string('reason')->default('CHARGEBACK_FRAUD');
            $table->jsonb('evidence')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index('transaction_id');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
