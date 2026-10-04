<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name')->default('Default API Key');
            $table->string('type')->default('LIVE'); // LIVE, TEST
            $table->string('public_key')->unique();  // e.g. tiq_live_pub_...
            $table->string('secret_key_hash');       // hashed secret: tiq_live_sec_...
            $table->string('secret_key_preview', 32); // e.g. tiq_...9f2a
            $table->jsonb('permissions')->nullable(); // scopes
            $table->jsonb('ip_whitelist')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('ACTIVE'); // ACTIVE, REVOKED
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
