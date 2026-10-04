<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\ReconciliationException;
use App\Models\ReconciliationRun;
use App\Models\Transaction;
use App\Services\Reconciliation\ClearingFileParserService;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconciliationEngineTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;
    protected string $secretKey;
    protected ReconciliationService $reconciliationService;
    protected ClearingFileParserService $fileParser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->firstOrFail();

        $keyPair = ApiKey::createKeyPair($this->merchant, 'Recon Test Key', 'TEST', ['payments:read', 'payments:write']);
        $this->secretKey = $keyPair['secret_key'];

        $this->reconciliationService = app(ReconciliationService::class);
        $this->fileParser = app(ClearingFileParserService::class);
    }

    public function test_clearing_file_parser_parses_csv_and_json_accurately(): void
    {
        // 1. Test CSV format
        $csvContent = "provider_reference,transaction_reference,amount,fee,currency,status,paid_at\n" .
                      "PROV-CSV-001,TXN-INT-001,10000.00,150.00,NGN,SUCCESS,2026-09-23 08:00:00\n" .
                      "PROV-CSV-002,TXN-INT-002,2500.50,37.50,NGN,PAID,2026-09-23 08:05:00";

        $parsedCsv = $this->fileParser->parse($csvContent, 'csv');
        $this->assertCount(2, $parsedCsv);
        $this->assertEquals('PROV-CSV-001', $parsedCsv[0]['provider_reference']);
        $this->assertEquals('TXN-INT-001', $parsedCsv[0]['transaction_reference']);
        $this->assertEquals(1000000, $parsedCsv[0]['amount']); // ₦10,000.00 in kobo
        $this->assertEquals(15000, $parsedCsv[0]['fee']);
        $this->assertEquals('SUCCESS', $parsedCsv[0]['status']);

        $this->assertEquals(250050, $parsedCsv[1]['amount']); // ₦2,500.50 in kobo
        $this->assertEquals('SUCCESS', $parsedCsv[1]['status']); // normalized from 'PAID'

        // 2. Test JSON format
        $jsonContent = json_encode([
            'records' => [
                [
                    'gateway_reference' => 'PROV-JSON-001',
                    'order_id' => 'TXN-INT-JSON-001',
                    'gross_amount' => '5000.00',
                    'fee' => '75.00',
                    'status' => 'successful',
                ],
            ],
        ]);

        $parsedJson = $this->fileParser->parse($jsonContent, 'json');
        $this->assertCount(1, $parsedJson);
        $this->assertEquals('PROV-JSON-001', $parsedJson[0]['provider_reference']);
        $this->assertEquals(500000, $parsedJson[0]['amount']);
        $this->assertEquals('SUCCESS', $parsedJson[0]['status']);
    }

    public function test_perfect_reconciliation_produces_100_percent_match_rate(): void
    {
        $date = '2026-08-10';

        // Create 2 internal successful transactions
        $txn1 = Transaction::create([
            'merchant_id' => $this->merchant->id,
            'reference' => 'TXN-PERFECT-' . Str::random(8),
            'provider_reference' => 'PROV-PERFECT-01',
            'amount' => 1000000, // ₦10,000.00
            'fee_amount' => 15000,
            'net_amount' => 985000,
            'currency' => 'NGN',
            'status' => 'SUCCESS',
            'payment_method' => 'CARD',
            'provider' => 'SIMULATED_GATEWAY',
            'created_at' => "{$date} 10:00:00",
        ]);

        $txn2 = Transaction::create([
            'merchant_id' => $this->merchant->id,
            'reference' => 'TXN-PERFECT-' . Str::random(8),
            'provider_reference' => 'PROV-PERFECT-02',
            'amount' => 500000, // ₦5,000.00
            'fee_amount' => 7500,
            'net_amount' => 492500,
            'currency' => 'NGN',
            'status' => 'SUCCESS',
            'payment_method' => 'BANK_TRANSFER',
            'provider' => 'SIMULATED_GATEWAY',
            'created_at' => "{$date} 11:00:00",
        ]);

        $providerRecords = [
            [
                'provider_reference' => 'PROV-PERFECT-01',
                'transaction_reference' => $txn1->reference,
                'amount' => 1000000,
                'fee' => 15000,
                'currency' => 'NGN',
                'status' => 'SUCCESS',
                'paid_at' => "{$date} 10:00:00",
                'raw_data' => [],
            ],
            [
                'provider_reference' => 'PROV-PERFECT-02',
                'transaction_reference' => $txn2->reference,
                'amount' => 500000,
                'fee' => 7500,
                'currency' => 'NGN',
                'status' => 'SUCCESS',
                'paid_at' => "{$date} 11:00:00",
                'raw_data' => [],
            ],
        ];

        $run = $this->reconciliationService->processReconciliation(
            merchant: $this->merchant,
            providerRecords: $providerRecords,
            provider: 'SIMULATED_GATEWAY',
            sourceFileName: 'clean_settlement.csv',
            reconciliationDate: $date
        );

        $this->assertEquals(ReconciliationRun::STATUS_COMPLETED, $run->status);
        $this->assertEquals(2, $run->matched_records);
        $this->assertEquals(0, $run->mismatched_records);
        $this->assertEquals(1500000, $run->matched_volume_minor);
        $this->assertEquals(0, $run->mismatched_volume_minor);
        $this->assertEquals(100.0, $run->summary['match_rate_percent']);
        $this->assertCount(0, $run->exceptions);
    }

    public function test_detects_missing_in_internal_exception(): void
    {
        $date = '2026-08-11';

        // Provider file has a record that was never captured internally
        $providerRecords = [
            [
                'provider_reference' => 'PROV-GHOST-999',
                'transaction_reference' => 'TXN-UNKNOWN-999',
                'amount' => 2000000, // ₦20,000.00
                'fee' => 30000,
                'currency' => 'NGN',
                'status' => 'SUCCESS',
                'paid_at' => "{$date} 10:00:00",
                'raw_data' => [],
            ],
        ];

        $run = $this->reconciliationService->processReconciliation(
            merchant: $this->merchant,
            providerRecords: $providerRecords,
            provider: 'SIMULATED_GATEWAY',
            sourceFileName: 'ghost_report.csv',
            reconciliationDate: $date
        );

        $this->assertEquals(0, $run->matched_records);
        $this->assertEquals(1, $run->mismatched_records);
        $this->assertEquals(2000000, $run->mismatched_volume_minor);
        $this->assertCount(1, $run->exceptions);

        $exc = $run->exceptions->first();
        $this->assertEquals(ReconciliationException::TYPE_MISSING_IN_INTERNAL, $exc->exception_type);
        $this->assertEquals('PROV-GHOST-999', $exc->provider_reference);
        $this->assertEquals(2000000, $exc->provider_amount_minor);
        $this->assertEquals(ReconciliationException::STATUS_OPEN, $exc->status);
    }

    public function test_detects_missing_in_provider_exception(): void
    {
        $date = '2026-08-12';

        // Internal DB has a SUCCESS transaction on this date that is NOT in provider file
        $internalTxn = Transaction::create([
            'merchant_id' => $this->merchant->id,
            'reference' => 'TXN-PHANTOM-' . Str::random(8),
            'provider_reference' => 'PROV-PHANTOM-888',
            'amount' => 800000, // ₦8,000.00
            'fee_amount' => 12000,
            'net_amount' => 788000,
            'currency' => 'NGN',
            'status' => 'SUCCESS',
            'payment_method' => 'CARD',
            'provider' => 'SIMULATED_GATEWAY',
            'created_at' => "{$date} 12:00:00",
        ]);

        // Provider records array does NOT contain this transaction
        $providerRecords = [
            [
                'provider_reference' => 'PROV-OTHER-123',
                'transaction_reference' => 'TXN-OTHER-123',
                'amount' => 50000,
                'fee' => 750,
                'currency' => 'NGN',
                'status' => 'SUCCESS',
                'paid_at' => "{$date} 12:00:00",
                'raw_data' => [],
            ],
        ];

        $run = $this->reconciliationService->processReconciliation(
            merchant: $this->merchant,
            providerRecords: $providerRecords,
            provider: 'SIMULATED_GATEWAY',
            sourceFileName: 'missing_provider.csv',
            reconciliationDate: $date
        );

        $missingInProviderExc = $run->exceptions->firstWhere('exception_type', ReconciliationException::TYPE_MISSING_IN_PROVIDER);
        $this->assertNotNull($missingInProviderExc);
        $this->assertEquals($internalTxn->id, $missingInProviderExc->transaction_id);
        $this->assertEquals(800000, $missingInProviderExc->internal_amount_minor);
    }

    public function test_detects_amount_mismatch_exception(): void
    {
        $date = '2026-08-13';

        $internalTxn = Transaction::create([
            'merchant_id' => $this->merchant->id,
            'reference' => 'TXN-AMT-' . Str::random(8),
            'provider_reference' => 'PROV-AMT-555',
            'amount' => 1000000, // ₦10,000.00
            'fee_amount' => 15000,
            'net_amount' => 985000,
            'currency' => 'NGN',
            'status' => 'SUCCESS',
            'payment_method' => 'CARD',
            'provider' => 'SIMULATED_GATEWAY',
            'created_at' => "{$date} 12:00:00",
        ]);

        // Provider file reports ₦9,500.00 (50,000 minor unit variance)
        $providerRecords = [
            [
                'provider_reference' => 'PROV-AMT-555',
                'transaction_reference' => $internalTxn->reference,
                'amount' => 950000,
                'fee' => 14250,
                'currency' => 'NGN',
                'status' => 'SUCCESS',
                'paid_at' => "{$date} 12:00:00",
                'raw_data' => [],
            ],
        ];

        $run = $this->reconciliationService->processReconciliation(
            merchant: $this->merchant,
            providerRecords: $providerRecords,
            provider: 'SIMULATED_GATEWAY',
            sourceFileName: 'amount_mismatch.csv',
            reconciliationDate: $date
        );

        $exc = $run->exceptions->firstWhere('exception_type', ReconciliationException::TYPE_AMOUNT_MISMATCH);
        $this->assertNotNull($exc);
        $this->assertEquals(1000000, $exc->internal_amount_minor);
        $this->assertEquals(950000, $exc->provider_amount_minor);
        $this->assertEquals(50000, $exc->discrepancy_details['variance_minor']);
    }

    public function test_auto_resolves_pending_transaction_when_provider_confirms_success(): void
    {
        $date = '2026-08-14';

        // Internal transaction left in PENDING due to timeout
        $pendingTxn = Transaction::create([
            'merchant_id' => $this->merchant->id,
            'reference' => 'TXN-PENDING-' . Str::random(8),
            'provider_reference' => 'PROV-RESOLVE-777',
            'amount' => 1200000, // ₦12,000.00
            'fee_amount' => 18000,
            'net_amount' => 1182000,
            'currency' => 'NGN',
            'status' => 'PENDING',
            'payment_method' => 'CARD',
            'provider' => 'SIMULATED_GATEWAY',
            'created_at' => "{$date} 10:00:00",
        ]);

        // Provider settlement report confirms funds were cleared (SUCCESS)
        $providerRecords = [
            [
                'provider_reference' => 'PROV-RESOLVE-777',
                'transaction_reference' => $pendingTxn->reference,
                'amount' => 1200000,
                'fee' => 18000,
                'currency' => 'NGN',
                'status' => 'SUCCESS',
                'paid_at' => "{$date} 10:00:00",
                'raw_data' => [],
            ],
        ];

        $run = $this->reconciliationService->processReconciliation(
            merchant: $this->merchant,
            providerRecords: $providerRecords,
            provider: 'SIMULATED_GATEWAY',
            sourceFileName: 'resolve_pending.csv',
            reconciliationDate: $date
        );

        // Transaction state must be updated to SUCCESS
        $pendingTxn->refresh();
        $this->assertEquals('SUCCESS', $pendingTxn->status);

        // Exception must be marked as RESOLVED automatically
        $exc = $run->exceptions->firstWhere('transaction_id', $pendingTxn->id);
        $this->assertNotNull($exc);
        $this->assertEquals(ReconciliationException::STATUS_RESOLVED, $exc->status);
        $this->assertTrue($exc->discrepancy_details['auto_recovery']);
        $this->assertNotNull($exc->resolved_at);

        // Run counts this as matched
        $this->assertEquals(1, $run->matched_records);
        $this->assertEquals(1200000, $run->matched_volume_minor);
    }

    public function test_manual_exception_resolution_workflow(): void
    {
        $run = ReconciliationRun::create([
            'run_reference' => 'REC-TEST-' . Str::random(6),
            'provider' => 'SIMULATED_GATEWAY',
            'reconciliation_date' => now()->toDateString(),
            'status' => ReconciliationRun::STATUS_COMPLETED,
        ]);

        $exception = ReconciliationException::create([
            'reconciliation_run_id' => $run->id,
            'exception_type' => ReconciliationException::TYPE_MISSING_IN_INTERNAL,
            'provider_reference' => 'PROV-MANUAL-001',
            'provider_amount_minor' => 100000,
            'status' => ReconciliationException::STATUS_OPEN,
        ]);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->postJson("/api/v1/reconciliation/exceptions/{$exception->id}/resolve", [
            'action' => 'RESOLVED',
            'notes' => 'Investigated with payment gateway support: transaction was legitimate and credited.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'RESOLVED')
            ->assertJsonPath('data.resolution_notes', 'Investigated with payment gateway support: transaction was legitimate and credited.');

        $exception->refresh();
        $this->assertEquals(ReconciliationException::STATUS_RESOLVED, $exception->status);
        $this->assertNotNull($exception->resolved_at);
    }

    public function test_api_endpoints_for_runs_exceptions_and_sample_generation(): void
    {
        // 1. Generate sample clearing file with auto_run=true
        $generateRes = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->postJson('/api/v1/reconciliation/generate-sample-file', [
            'format' => 'csv',
            'auto_run' => true,
        ]);

        $generateRes->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['id', 'run_reference', 'match_rate_percent', 'exceptions']]);

        $runId = $generateRes->json('data.id');

        // 2. Fetch runs list
        $listRes = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->getJson('/api/v1/reconciliation/runs');

        $listRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => [['id', 'run_reference', 'status', 'match_rate_percent']]]);

        // 3. Fetch single run details
        $showRes = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->getJson("/api/v1/reconciliation/runs/{$runId}");

        $showRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $runId);

        // 4. Fetch exceptions list
        $excRes = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->getJson('/api/v1/reconciliation/exceptions');

        $excRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => [['id', 'exception_type', 'status']]]);
    }
}
