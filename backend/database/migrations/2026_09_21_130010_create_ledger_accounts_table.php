<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->nullable()->constrained('merchants')->cascadeOnDelete();
            $table->string('account_number')->unique();
            $table->string('name');
            // Standard Chart of Accounts: ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE
            $table->string('type'); 
            // Classification: MERCHANT_AVAILABLE, MERCHANT_PENDING, PLATFORM_FEE_REVENUE, PROVIDER_CLEARING, ESCROW
            $table->string('classification');
            $table->string('currency', 3)->default('NGN');
            // Stored in minor units (signed)
            $table->bigInteger('balance')->default(0);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();

            $table->index(['merchant_id', 'classification']);
            $table->index('classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
