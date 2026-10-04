<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('id')->constrained('roles')->nullOnDelete();
            $table->foreignUuid('merchant_id')->nullable()->after('role_id')->constrained('merchants')->nullOnDelete();
            $table->string('status')->default('ACTIVE')->after('password');
            $table->string('phone')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['merchant_id']);
            $table->dropColumn(['role_id', 'merchant_id', 'status', 'phone']);
        });
    }
};
