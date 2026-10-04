<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('provider'); // SIMULATED_GATEWAY, PAYSTACK_SIMULATOR, etc.
            $table->string('provider_reference')->nullable();
            $table->string('status');   // SUCCESS, FAILED, PENDING, TIMEOUT
            $table->string('error_code')->nullable();
            $table->string('error_message')->nullable();
            $table->jsonb('gateway_request')->nullable();
            $table->jsonb('gateway_response')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['transaction_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
