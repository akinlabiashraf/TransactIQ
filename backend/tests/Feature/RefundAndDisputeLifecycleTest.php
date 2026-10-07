<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Dispute;
use App\Models\Merchant;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundAndDisputeLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;
    protected string $apiKeySecret;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->firstOrFail();

        $keyPair = ApiKey::createKeyPair($this->merchant, 'Refund Lifecycle Key', 'TEST', [
            'payments:read',
            'payments:write',
            'refunds:write',
            'settlements:write',
        ]);
        $this->apiKeySecret = $keyPair['secret_key'];

        $adminRole = Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Admin']);
        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin_test@transactiq.io'],
            [
                'name' => 'Test Admin',
                'password' => bcrypt('password'),
                'role_id' => $adminRole->id,
                'status' => 'ACTIVE',
            ]
        );
    }

    /**
     * Helper to create a successful payment transaction.
     */
    protected function createSuccessfulPayment(int $amount = 500000): Transaction
    {
        $res = $this->withHeaders([
            'X-Api-Key' => $this->apiKeySecret,
            'Idempotency-Key' => 'idemp_ref_' . Str::random(16),
            'X-Stress-Test' => 'true',
        ])->postJson('/api/v1/payments', [
            'amount' => $amount,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => [
                'email' => 'refund_buyer@example.com',
                'name' => 'Refund Test Customer',
            ],
            'card' => [
                'number' => '4000000000000001',
                'exp_month' => '12',
                'exp_year' => '2028',
                'cvv' => '123',
            ],
        ]);

        $res->assertStatus(201);
        return Transaction::where('reference', $res->json('data.reference'))->firstOrFail();
    }

    /**
     * Test full refund execution with FSM update and double-entry ledger balance.
     */
    public function test_full_refund_transitions_transaction_to_refunded_and_balances_ledger(): void
    {
        $txn = $this->createSuccessfulPayment(500000); // ₦5,000

        $response = $this->withHeaders([
            'X-Api-Key' => $this->apiKeySecret,
        ])->postJson("/api/v1/payments/{$txn->reference}/refund", [
            'reason' => Refund::REASON_CUSTOMER_REQUEST,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.amount', 500000)
            ->assertJsonPath('data.transaction_new_status', Transaction::STATUS_REFUNDED)
            ->assertJsonPath('data.remaining_refundable', 0);

        // Verify FSM state
        $txn->refresh();
        $this->assertEquals(Transaction::STATUS_REFUNDED, $txn->status);
        $this->assertEquals(500000, $txn->totalRefundedAmount());
        $this->assertEquals(0, $txn->refundableAmount());

        // Verify general ledger invariance: zero drift!
        $ledgerService = app(LedgerService::class);
        $integrity = $ledgerService->verifyLedgerIntegrity('NGN');
        $this->assertTrue($integrity['is_balanced']);
        $this->assertEquals(0, $integrity['discrepancy']);
    }

    /**
     * Test partial refunds and boundary enforcement.
     */
    public function test_partial_refund_transitions_to_partially_refunded_and_tracks_balance(): void
    {
        $txn = $this->createSuccessfulPayment(1000000); // ₦10,000

        // 1. First partial refund of ₦3,000
        $res1 = $this->withHeaders([
            'X-Api-Key' => $this->apiKeySecret,
        ])->postJson("/api/v1/payments/{$txn->reference}/refund", [
            'amount' => 300000,
            'reason' => Refund::REASON_CUSTOMER_REQUEST,
        ]);

        $res1->assertStatus(201)
            ->assertJsonPath('data.transaction_new_status', Transaction::STATUS_PARTIALLY_REFUNDED)
            ->assertJsonPath('data.remaining_refundable', 700000);

        $txn->refresh();
        $this->assertEquals(Transaction::STATUS_PARTIALLY_REFUNDED, $txn->status);

        // 2. Second partial refund of remaining ₦7,000
        $res2 = $this->withHeaders([
            'X-Api-Key' => $this->apiKeySecret,
        ])->postJson("/api/v1/payments/{$txn->reference}/refund", [
            'amount' => 700000,
            'reason' => Refund::REASON_ORDER_CANCELLED,
        ]);

        $res2->assertStatus(201)
            ->assertJsonPath('data.transaction_new_status', Transaction::STATUS_REFUNDED)
            ->assertJsonPath('data.remaining_refundable', 0);

        // 3. Attempting another refund must be rejected with 422
        $res3 = $this->withHeaders([
            'X-Api-Key' => $this->apiKeySecret,
        ])->postJson("/api/v1/payments/{$txn->reference}/refund", [
            'amount' => 100000,
        ]);

        $res3->assertStatus(422);

        // Verify ledger integrity
        $integrity = app(LedgerService::class)->verifyLedgerIntegrity('NGN');
        $this->assertTrue($integrity['is_balanced']);
        $this->assertEquals(0, $integrity['discrepancy']);
    }

    /**
     * Test dispute lifecycle: Open -> Submit Evidence -> Resolve WON.
     */
    public function test_dispute_creation_places_funds_in_escrow_reserve_and_resolves_won(): void
    {
        $txn = $this->createSuccessfulPayment(250000); // ₦2,500

        // 1. Open Dispute
        $openRes = $this->withHeaders([
            'X-Api-Key' => $this->apiKeySecret,
        ])->postJson('/api/v1/disputes', [
            'transaction_reference' => $txn->reference,
            'reason' => Dispute::REASON_CHARGEBACK_FRAUD,
        ]);

        $openRes->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', Dispute::STATUS_OPEN);

        $disputeRef = $openRes->json('data.reference');

        $txn->refresh();
        $this->assertEquals(Transaction::STATUS_DISPUTED, $txn->status);

        // 2. Submit Evidence
        $evidenceRes = $this->withHeaders([
            'X-Api-Key' => $this->apiKeySecret,
        ])->postJson("/api/v1/disputes/{$disputeRef}/evidence", [
            'evidence' => [
                'delivery_proof_url' => 'https://shipping.example.com/proof/12345',
                'customer_signature' => 'Signed by buyer at 12:00PM',
            ],
        ]);

        $evidenceRes->assertStatus(200)
            ->assertJsonPath('data.status', Dispute::STATUS_UNDER_REVIEW);

        // 3. Resolve Dispute as WON
        $resolveRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/disputes/{$disputeRef}/resolve", [
                'outcome' => Dispute::STATUS_WON,
                'resolution_note' => 'Merchant provided valid signed proof of delivery.',
            ]);

        $resolveRes->assertStatus(200)
            ->assertJsonPath('data.status', Dispute::STATUS_WON);

        $txn->refresh();
        $this->assertEquals(Transaction::STATUS_SUCCESS, $txn->status);

        // Verify ledger integrity
        $integrity = app(LedgerService::class)->verifyLedgerIntegrity('NGN');
        $this->assertTrue($integrity['is_balanced']);
        $this->assertEquals(0, $integrity['discrepancy']);
    }

    /**
     * Test dispute resolution LOST transitions transaction to REVERSED.
     */
    public function test_dispute_resolution_lost_reverses_transaction(): void
    {
        $txn = $this->createSuccessfulPayment(300000); // ₦3,000

        $openRes = $this->withHeaders([
            'X-Api-Key' => $this->apiKeySecret,
        ])->postJson('/api/v1/disputes', [
            'transaction_reference' => $txn->reference,
            'reason' => Dispute::REASON_UNRECOGNIZED,
        ]);

        $openRes->assertStatus(201);
        $disputeRef = $openRes->json('data.reference');

        // Resolve as LOST
        $resolveRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/disputes/{$disputeRef}/resolve", [
                'outcome' => Dispute::STATUS_LOST,
                'resolution_note' => 'Issuing bank confirmed card was cloned.',
            ]);

        $resolveRes->assertStatus(200)
            ->assertJsonPath('data.status', Dispute::STATUS_LOST);

        $txn->refresh();
        $this->assertEquals(Transaction::STATUS_REVERSED, $txn->status);

        // Verify ledger integrity
        $integrity = app(LedgerService::class)->verifyLedgerIntegrity('NGN');
        $this->assertTrue($integrity['is_balanced']);
        $this->assertEquals(0, $integrity['discrepancy']);
    }

    /**
     * Test poller command reconciles PENDING payments to SUCCESS.
     */
    public function test_poller_artisan_command_resolves_pending_transactions(): void
    {
        // Create an orphaned PENDING transaction
        $customer = $this->merchant->customers()->firstOrCreate(
            ['email' => 'pending_user@example.com'],
            ['customer_code' => 'CUST-' . strtoupper(Str::random(8)), 'name' => 'Pending User']
        );
        $pendingTxn = Transaction::create([
            'reference' => 'TXN-PEND-' . strtoupper(Str::random(8)),
            'merchant_id' => $this->merchant->id,
            'customer_id' => $customer->id,
            'amount' => 450000,
            'fee_amount' => 10000,
            'net_amount' => 440000,
            'currency' => 'NGN',
            'status' => Transaction::STATUS_PENDING,
            'payment_method' => 'CARD',
            'provider' => 'SIMULATED_PRIMARY',
            'idempotency_key' => 'idemp_poller_' . Str::random(12),
        ]);

        $exitCode = Artisan::call('payments:poll-pending', ['--force-success' => true]);
        $this->assertEquals(0, $exitCode);

        $pendingTxn->refresh();
        $this->assertEquals(Transaction::STATUS_SUCCESS, $pendingTxn->status);
        $this->assertNotNull($pendingTxn->paid_at);

        // Verify ledger integrity
        $integrity = app(LedgerService::class)->verifyLedgerIntegrity('NGN');
        $this->assertTrue($integrity['is_balanced']);
        $this->assertEquals(0, $integrity['discrepancy']);
    }
}
