<?php

namespace Database\Seeders;

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the foundational data for TransactIQ.
     */
    public function run(): void
    {
        // 1. Seed Roles
        $adminRole = Role::firstOrCreate(
            ['slug' => Role::ADMIN],
            ['name' => 'Administrator', 'description' => 'Full platform and infrastructure administration']
        );

        $merchantRole = Role::firstOrCreate(
            ['slug' => Role::MERCHANT],
            ['name' => 'Merchant', 'description' => 'Merchant business operator and API consumer']
        );

        $auditorRole = Role::firstOrCreate(
            ['slug' => Role::AUDITOR],
            ['name' => 'Auditor', 'description' => 'Financial and regulatory compliance auditor']
        );

        $opsRole = Role::firstOrCreate(
            ['slug' => Role::OPERATIONS],
            ['name' => 'Operations Officer', 'description' => 'Payment troubleshooting, disputes and reconciliation triage']
        );

        // 2. Seed Internal Platform Users
        $admin = User::firstOrCreate(
            ['email' => 'admin@transactiq.io'],
            [
                'name' => 'Platform Administrator',
                'password' => Hash::make('Password123!'),
                'role_id' => $adminRole->id,
                'status' => 'ACTIVE',
                'phone' => '+2348000000001',
            ]
        );

        $ops = User::firstOrCreate(
            ['email' => 'ops@transactiq.io'],
            [
                'name' => 'Operations Lead',
                'password' => Hash::make('Password123!'),
                'role_id' => $opsRole->id,
                'status' => 'ACTIVE',
                'phone' => '+2348000000002',
            ]
        );

        $auditor = User::firstOrCreate(
            ['email' => 'auditor@transactiq.io'],
            [
                'name' => 'Senior Auditor',
                'password' => Hash::make('Password123!'),
                'role_id' => $auditorRole->id,
                'status' => 'ACTIVE',
                'phone' => '+2348000000003',
            ]
        );

        // 3. Seed Demo Merchant
        $merchant = Merchant::firstOrCreate(
            ['merchant_code' => 'MC-SWIFTPAY'],
            [
                'name' => 'SwiftPay Retail Enterprises',
                'business_email' => 'merchant@swiftpay.com',
                'business_phone' => '+2348023456789',
                'country' => 'NG',
                'default_currency' => 'NGN',
                'status' => 'ACTIVE',
                'webhook_url' => 'https://webhook.site/test-transactiq',
                'webhook_secret' => 'whsec_' . Str::random(32),
                'fee_basis_points' => 150, // 1.5%
                'fee_flat_minor' => 10000, // ₦100 flat fee in kobo
                'settlement_bank_details' => [
                    'bank_name' => 'Zenith Bank PLC',
                    'account_number' => '1012345678',
                    'account_name' => 'SwiftPay Retail Ltd',
                ],
            ]
        );

        // Seed Merchant Owner User
        User::firstOrCreate(
            ['email' => 'admin@swiftpay.com'],
            [
                'name' => 'SwiftPay Merchant Admin',
                'password' => Hash::make('Password123!'),
                'role_id' => $merchantRole->id,
                'merchant_id' => $merchant->id,
                'status' => 'ACTIVE',
                'phone' => '+2348023456789',
            ]
        );

        // 4. Seed API Keys for Demo Merchant
        if ($merchant->apiKeys()->count() === 0) {
            ApiKey::createKeyPair($merchant, 'Production Live Key', 'LIVE', ['payments:read', 'payments:write', 'refunds:write']);
            ApiKey::createKeyPair($merchant, 'Sandbox Test Key', 'TEST', ['payments:read', 'payments:write']);
        }

        // Seed deterministic test key for k6 stress benchmarking and developer integration
        ApiKey::firstOrCreate(
            ['public_key' => 'tiq_test_pub_swiftpay_stress_key'],
            [
                'merchant_id' => $merchant->id,
                'name' => 'Deterministic Benchmark Key',
                'type' => 'TEST',
                'secret_key_hash' => hash('sha256', 'tiq_test_sec_swiftpay_stress_key_000000000000'),
                'secret_key_preview' => 'tiq_...000000',
                'permissions' => ['payments:read', 'payments:write', 'settlements:write'],
                'status' => 'ACTIVE',
            ]
        );

        // 5. Seed Core Chart of Accounts (Double-Entry General Ledger)
        // Platform Revenue Account
        LedgerAccount::firstOrCreate(
            ['account_number' => 'ACC-PLATFORM-REVENUE'],
            [
                'name' => 'TransactIQ Platform Revenue',
                'type' => LedgerAccount::TYPE_REVENUE,
                'classification' => LedgerAccount::CLASS_PLATFORM_REVENUE,
                'currency' => 'NGN',
                'balance' => 0,
                'status' => 'ACTIVE',
            ]
        );

        // Provider Clearing Account
        LedgerAccount::firstOrCreate(
            ['account_number' => 'ACC-PROVIDER-CLEARING'],
            [
                'name' => 'Payment Gateway Provider Clearing',
                'type' => LedgerAccount::TYPE_ASSET,
                'classification' => LedgerAccount::CLASS_PROVIDER_CLEARING,
                'currency' => 'NGN',
                'balance' => 0,
                'status' => 'ACTIVE',
            ]
        );

        // Merchant Available Balance Account
        LedgerAccount::firstOrCreate(
            ['account_number' => 'ACC-SWIFT-AVAIL'],
            [
                'merchant_id' => $merchant->id,
                'name' => 'SwiftPay Available Balance',
                'type' => LedgerAccount::TYPE_LIABILITY,
                'classification' => LedgerAccount::CLASS_MERCHANT_AVAILABLE,
                'currency' => 'NGN',
                'balance' => 0,
                'status' => 'ACTIVE',
            ]
        );

        // Merchant Pending Settlement Account
        LedgerAccount::firstOrCreate(
            ['account_number' => 'ACC-SWIFT-PEND'],
            [
                'merchant_id' => $merchant->id,
                'name' => 'SwiftPay Pending Settlement',
                'type' => LedgerAccount::TYPE_LIABILITY,
                'classification' => LedgerAccount::CLASS_MERCHANT_PENDING,
                'currency' => 'NGN',
                'balance' => 0,
                'status' => 'ACTIVE',
            ]
        );

        // 6. Seed Demo Customer
        Customer::firstOrCreate(
            ['customer_code' => 'CUST-1001'],
            [
                'merchant_id' => $merchant->id,
                'email' => 'customer@example.com',
                'name' => 'Chinedu Okafor',
                'phone' => '+2348012345678',
                'metadata' => [
                    'loyalty_tier' => 'Gold',
                    'signup_source' => 'Mobile Checkout',
                ],
            ]
        );
    }
}
