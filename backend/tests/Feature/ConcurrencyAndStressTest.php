<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConcurrencyAndStressTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;
    protected string $apiKeySecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->firstOrFail();

        $keyPair = ApiKey::createKeyPair($this->merchant, 'Stress Test Key', 'TEST', ['payments:read', 'payments:write', 'settlements:write']);
        $this->apiKeySecret = $keyPair['secret_key'];
    }

    /**
     * Verify that sending multiple requests with the identical Idempotency-Key
     * results in strictly one database record and 100% consistent replayed responses.
     */
    public function test_concurrent_requests_with_same_idempotency_key_create_only_one_transaction(): void
    {
        $idempotencyKey = 'idemp_stress_' . Str::random(20);
        $payload = [
            'amount' => 500000, // ₦5,000.00
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => [
                'email' => 'stress_customer@example.com',
                'name' => 'Concurrent Stress Buyer',
            ],
            'metadata' => ['order_id' => 'ORD-CONCURRENT-001'],
        ];

        $responses = [];

        // Simulate a rapid burst of identical requests (e.g. client double-click or network retry)
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->withHeaders([
                'X-Api-Key' => $this->apiKeySecret,
                'Idempotency-Key' => $idempotencyKey,
                'X-Stress-Test' => 'true',
            ])->postJson('/api/v1/payments', $payload);
        }

        // 1. All requests must succeed
        $references = [];
        $hasReplayHeader = false;

        foreach ($responses as $idx => $res) {
            $this->assertContains($res->status(), [200, 201], "Request #{$idx} failed with status {$res->status()}: " . $res->getContent());
            $data = $res->json('data');
            $this->assertNotNull($data);
            $references[] = $data['reference'];

            if ($res->headers->get('X-Idempotent-Replay') === 'true') {
                $hasReplayHeader = true;
            }
        }

        // 2. All references returned across all 5 requests must be identical
        $uniqueReferences = array_unique($references);
        $this->assertCount(1, $uniqueReferences, 'Multiple transaction references were created for the same Idempotency-Key!');

        // 3. Exactly 1 transaction record exists in the database
        $dbCount = Transaction::where('merchant_id', $this->merchant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        $this->assertEquals(1, $dbCount, 'Duplicate database transactions were created!');

        // 4. At least one of the subsequent requests returned X-Idempotent-Replay header
        $this->assertTrue($hasReplayHeader, 'Expected at least one request to return X-Idempotent-Replay: true header');
    }

    /**
     * Verify that concurrent payment processing maintains zero drift across the general ledger.
     */
    public function test_concurrent_payment_processing_maintains_zero_ledger_variance(): void
    {
        $ledgerService = app(LedgerService::class);

        // Process 4 distinct successful transactions
        for ($i = 0; $i < 4; $i++) {
            $res = $this->withHeaders([
                'X-Api-Key' => $this->apiKeySecret,
                'Idempotency-Key' => 'idemp_flow_' . Str::random(20),
                'X-Stress-Test' => 'true',
            ])->postJson('/api/v1/payments', [
                'amount' => 150000 + ($i * 10000), // varying amounts
                'currency' => 'NGN',
                'payment_method' => 'CARD',
                'customer' => [
                    'email' => "stress_{$i}@example.com",
                    'name' => "Stress User {$i}",
                ],
                'metadata' => ['order_id' => "ORD-STRESS-{$i}"],
            ]);

            $this->assertEquals(201, $res->status(), "Payment {$i} failed: " . $res->getContent());
            $this->assertEquals(Transaction::STATUS_SUCCESS, $res->json('data.status'), "Transaction {$i} was not successful: " . $res->getContent());
        }

        // Audit the double-entry ledger
        $audit = $ledgerService->verifyLedgerIntegrity('NGN');

        $this->assertTrue($audit['is_balanced'], 'Ledger is unbalanced!');
        $this->assertEquals(0, $audit['discrepancy'], 'Ledger discrepancy is non-zero!');
        $this->assertGreaterThan(0, $audit['total_entries'], 'Expected ledger entries to be created');
        $this->assertEquals($audit['total_debits'], $audit['total_credits'], 'Total debits must strictly equal total credits');
    }

    /**
     * Verify that the artisan command ledger:verify exits with code 0 and reports zero drift.
     */
    public function test_ledger_verify_artisan_command_returns_exit_code_zero(): void
    {
        $exitCode = Artisan::call('ledger:verify', ['--currency' => 'NGN']);
        $output = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('ZERO DRIFT', $output);
        $this->assertStringContainsString('BALANCED (DEBITS === CREDITS)', $output);
    }
}
