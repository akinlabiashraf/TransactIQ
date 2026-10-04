<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('merchant_code')->unique();
            $table->string('name');
            $table->string('business_email')->unique();
            $table->string('business_phone')->nullable();
            $table->string('country', 2)->default('NG');
            $table->string('default_currency', 3)->default('NGN');
            $table->string('status')->default('ACTIVE'); // ACTIVE, SUSPENDED, PENDING_VERIFICATION
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->unsignedInteger('fee_basis_points')->default(150); // 1.5% default platform fee
            $table->unsignedBigInteger('fee_flat_minor')->default(0); // flat fee in minor units (kobo/cents)
            $table->jsonb('settlement_bank_details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
