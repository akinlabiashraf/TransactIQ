<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignUuid('debit_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignUuid('credit_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount'); // minor units
            $table->string('currency', 3)->default('NGN');
            $table->string('entry_type');         // PAYMENT_CAPTURED, PLATFORM_FEE, SETTLEMENT_PAYOUT, REVERSAL
            $table->string('reference')->unique(); // journal entry reference e.g. JRN-20260921-00001
            $table->string('description');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['transaction_id', 'entry_type']);
            $table->index('debit_account_id');
            $table->index('credit_account_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
