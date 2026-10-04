<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use DatabaseTransactions;
    public function test_roles_and_users_are_seeded(): void
    {
        $this->assertDatabaseHas('roles', ['slug' => Role::ADMIN]);
        $this->assertDatabaseHas('roles', ['slug' => Role::MERCHANT]);
        $this->assertDatabaseHas('roles', ['slug' => Role::AUDITOR]);
        $this->assertDatabaseHas('roles', ['slug' => Role::OPERATIONS]);

        $admin = User::where('email', 'admin@transactiq.io')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());

        $merchantUser = User::where('email', 'admin@swiftpay.com')->first();
        $this->assertNotNull($merchantUser);
        $this->assertTrue($merchantUser->isMerchant());
        $this->assertNotNull($merchantUser->merchant_id);
    }

    public function test_merchant_and_api_keys_are_configured(): void
    {
        $merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->first();
        $this->assertNotNull($merchant);
        $this->assertEquals('NGN', $merchant->default_currency);
        $this->assertEquals(150, $merchant->fee_basis_points);

        $liveKey = ApiKey::where('merchant_id', $merchant->id)->where('type', 'LIVE')->first();
        $this->assertNotNull($liveKey);
        $this->assertStringStartsWith('tiq_live_pub_', $liveKey->public_key);
    }

    public function test_double_entry_ledger_accounts_are_initialized(): void
    {
        $revenue = LedgerAccount::where('account_number', 'ACC-PLATFORM-REVENUE')->first();
        $this->assertNotNull($revenue);
        $this->assertEquals(LedgerAccount::TYPE_REVENUE, $revenue->type);

        $clearing = LedgerAccount::where('account_number', 'ACC-PROVIDER-CLEARING')->first();
        $this->assertNotNull($clearing);
        $this->assertEquals(LedgerAccount::TYPE_ASSET, $clearing->type);

        $avail = LedgerAccount::where('account_number', 'ACC-SWIFT-AVAIL')->first();
        $this->assertNotNull($avail);
        $this->assertEquals(LedgerAccount::TYPE_LIABILITY, $avail->type);
    }

    public function test_transaction_state_machine_and_idempotency_constraint(): void
    {
        $merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->firstOrFail();
        $customer = Customer::where('merchant_id', $merchant->id)->firstOrFail();
        $idempKey = 'idemp-' . Str::random(16);

        $txn = Transaction::create([
            'reference' => 'TXN-' . date('Ymd') . '-' . Str::random(8),
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'amount' => 5000000, // ₦50,000.00 in kobo
            'fee_amount' => 75000,
            'net_amount' => 4925000,
            'currency' => 'NGN',
            'status' => Transaction::STATUS_INITIATED,
            'idempotency_key' => $idempKey,
            'payment_method' => 'CARD',
        ]);

        $this->assertNotNull($txn->id);
        $this->assertTrue($txn->canTransitionTo(Transaction::STATUS_PROCESSING));
        $this->assertFalse($txn->canTransitionTo(Transaction::STATUS_SUCCESS)); // cannot jump straight to SUCCESS

        // Test idempotency uniqueness constraint on database level
        $this->expectException(QueryException::class);
        Transaction::create([
            'reference' => 'TXN-' . date('Ymd') . '-' . Str::random(8),
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'amount' => 5000000,
            'currency' => 'NGN',
            'status' => Transaction::STATUS_INITIATED,
            'idempotency_key' => $idempKey, // duplicate key for same merchant!
        ]);
    }
}
