<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhookJob;
use App\Models\ApiKey;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use App\Services\Settlement\SettlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerAndSettlementTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;
    protected string $secretKey;
    protected LedgerService $ledgerService;
    protected SettlementService $settlementService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->firstOrFail();

        $keyPair = ApiKey::createKeyPair($this->merchant, 'Ledger Test Key', 'TEST', ['payments:read', 'payments:write']);
        $this->secretKey = $keyPair['secret_key'];

        $this->ledgerService = app(LedgerService::class);
        $this->settlementService = app(SettlementService::class);
    }

    public function test_payment_capture_creates_balanced_double_entry_journal_entries(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 1000000, // ₦10,000.00
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'ledger.capture@example.com'],
            'card' => [
                'number' => '4000 0000 0000 0001',
                'exp_month' => '12',
                'exp_year' => '2028',
                'cvv' => '123',
            ],
        ]);

        $response->assertStatus(201);
        $txnId = $response->json('data.id');

        $entries = LedgerEntry::where('transaction_id', $txnId)->get();

        // Exactly 2 journal entries: PAYMENT_CAPTURED (net) and PLATFORM_FEE (fee)
        $this->assertCount(2, $entries);

        $capturedEntry = $entries->firstWhere('entry_type', LedgerEntry::TYPE_PAYMENT_CAPTURED);
        $feeEntry = $entries->firstWhere('entry_type', LedgerEntry::TYPE_PLATFORM_FEE);

        $this->assertNotNull($capturedEntry);
        $this->assertNotNull($feeEntry);

        // Verify mathematical invariant: Net + Fee == Gross Amount
        $this->assertEquals(1000000, $capturedEntry->amount + $feeEntry->amount);

        // Verify account classifications
        $capturedEntry->load(['debitAccount', 'creditAccount']);
        $this->assertEquals(LedgerAccount::CLASS_PROVIDER_CLEARING, $capturedEntry->debitAccount->classification);
        $this->assertEquals(LedgerAccount::CLASS_MERCHANT_AVAILABLE, $capturedEntry->creditAccount->classification);

        $feeEntry->load(['debitAccount', 'creditAccount']);
        $this->assertEquals(LedgerAccount::CLASS_PROVIDER_CLEARING, $feeEntry->debitAccount->classification);
        $this->assertEquals(LedgerAccount::CLASS_PLATFORM_REVENUE, $feeEntry->creditAccount->classification);
    }

    public function test_ledger_invariant_holds_sum_debits_equals_sum_credits(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 500000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'integrity.check@example.com'],
            'card' => [
                'number' => '4000 0000 0000 0001',
                'exp_month' => '12',
                'exp_year' => '2028',
                'cvv' => '123',
            ],
        ]);

        $integrity = $this->ledgerService->verifyLedgerIntegrity('NGN');

        $this->assertTrue($integrity['is_balanced']);
        $this->assertEquals(0, $integrity['discrepancy']);
        $this->assertEquals($integrity['total_debits'], $integrity['total_credits']);
    }

    public function test_settlement_batch_creation_aggregates_transactions_and_moves_to_escrow(): void
    {
        // 1. Create 2 successful payments
        $res1 = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => 'idemp-' . Str::random(16),
        ])->postJson('/api/v1/payments', [
            'amount' => 300000, // ₦3,000.00
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'txn1@example.com'],
            'card' => ['number' => '4000 0000 0000 0001', 'exp_month' => '12', 'exp_year' => '2028', 'cvv' => '123'],
        ]);
        $txn1Id = $res1->json('data.id');

        $res2 = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => 'idemp-' . Str::random(16),
        ])->postJson('/api/v1/payments', [
            'amount' => 700000, // ₦7,000.00
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'txn2@example.com'],
            'card' => ['number' => '4000 0000 0000 0001', 'exp_month' => '12', 'exp_year' => '2028', 'cvv' => '123'],
        ]);
        $txn2Id = $res2->json('data.id');

        // 2. Generate Settlement Batch via API
        $settleResponse = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->postJson('/api/v1/settlements/generate', ['currency' => 'NGN']);

        $settleResponse->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['status', 'data' => ['id', 'settlement_reference', 'gross_amount', 'net_amount']]);

        $settlementId = $settleResponse->json('data.id');

        // Transactions must now be associated with the settlement
        $this->assertEquals($settlementId, Transaction::find($txn1Id)->settlement_id);
        $this->assertEquals($settlementId, Transaction::find($txn2Id)->settlement_id);

        // Double-entry escrow transfer entry must exist
        $escrowEntry = LedgerEntry::where('entry_type', 'SETTLEMENT_INITIATED')
            ->where('description', 'like', "%{$settleResponse->json('data.settlement_reference')}%")
            ->first();

        $this->assertNotNull($escrowEntry);
        $this->assertEquals($settleResponse->json('data.net_amount'), $escrowEntry->amount);
    }

    public function test_settlement_payout_completion_relieves_clearing_and_dispatches_webhook(): void
    {
        Queue::fake();

        // 1. Create transaction and generate settlement
        $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => 'idemp-' . Str::random(16),
        ])->postJson('/api/v1/payments', [
            'amount' => 500000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'payout.test@example.com'],
            'card' => ['number' => '4000 0000 0000 0001', 'exp_month' => '12', 'exp_year' => '2028', 'cvv' => '123'],
        ]);

        $batch = $this->settlementService->generateSettlementBatch($this->merchant, 'NGN');
        $this->assertNotNull($batch);

        // 2. Complete Payout via API
        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->postJson("/api/v1/settlements/{$batch->id}/complete", [
            'payout_reference' => 'WIRE-ZENITH-998877',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', Settlement::STATUS_COMPLETED)
            ->assertJsonPath('data.payout_reference', 'WIRE-ZENITH-998877');

        // Verify payout ledger entry
        $payoutEntry = LedgerEntry::where('entry_type', LedgerEntry::TYPE_SETTLEMENT_PAYOUT)
            ->where('description', 'like', "%{$batch->settlement_reference}%")
            ->first();

        $this->assertNotNull($payoutEntry);
        $this->assertEquals($batch->net_amount, $payoutEntry->amount);

        // Webhook for settlement.completed must be dispatched
        Queue::assertPushed(DispatchWebhookJob::class);
    }

    public function test_transactions_cannot_be_double_settled(): void
    {
        // First batch settles all currently unsettled transactions
        $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->postJson('/api/v1/settlements/generate', ['currency' => 'NGN']);

        // Second batch run finds no remaining unsettled transactions and returns no-op
        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->postJson('/api/v1/settlements/generate', ['currency' => 'NGN']);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'noop');
    }

    public function test_api_endpoints_for_ledger_accounts_entries_and_integrity(): void
    {
        // 1. Chart of Accounts
        $accRes = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->getJson('/api/v1/ledger/accounts');

        $accRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => [['account_number', 'name', 'balance', 'type']]]);

        // 2. Journal Entries
        $entryRes = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->getJson('/api/v1/ledger/entries');

        $entryRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // 3. Mathematical Integrity Verification
        $integRes = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->getJson('/api/v1/ledger/integrity');

        $integRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.is_balanced', true);
    }
}
