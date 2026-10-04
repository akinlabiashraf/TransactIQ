<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\Role;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ObservabilityAndAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;
    protected string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->firstOrFail();

        $keyPair = ApiKey::createKeyPair($this->merchant, 'Observability Test Key', 'TEST', ['payments:read', 'payments:write']);
        $this->secretKey = $keyPair['secret_key'];
    }

    public function test_requests_receive_unique_correlation_id_in_response_headers(): void
    {
        \Illuminate\Support\Facades\Redis::shouldReceive('ping')->andReturn('PONG');

        // 1. Automatic generation when not provided by client
        $response = $this->getJson('/api/v1/health');

        $this->assertTrue($response->headers->has('X-Correlation-ID'));
        $corrId = $response->headers->get('X-Correlation-ID');
        $this->assertNotEmpty($corrId);

        // 2. Propagation when explicitly provided by client
        $customId = 'test-trace-id-998877';
        $responseWithHeader = $this->withHeaders(['X-Correlation-ID' => $customId])
            ->getJson('/api/v1/health');

        $responseWithHeader->assertStatus(200);
        $this->assertEquals($customId, $responseWithHeader->headers->get('X-Correlation-ID'));
    }

    public function test_ledger_service_verifies_integrity_with_zero_discrepancy(): void
    {
        /** @var LedgerService $ledgerService */
        $ledgerService = app(LedgerService::class);

        $result = $ledgerService->verifyLedgerIntegrity('NGN');

        $this->assertTrue($result['is_balanced']);
        $this->assertEquals(0, $result['discrepancy']);
        $this->assertGreaterThanOrEqual(0, $result['total_entries']);
    }

    public function test_analytics_summary_endpoint_computes_accurate_metrics(): void
    {
        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/analytics/summary');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'overview' => [
                    'cleared_volume',
                    'fee_revenue',
                    'net_payout_volume',
                    'currency',
                    'total_transactions',
                    'successful_transactions',
                    'failed_transactions',
                    'pending_transactions',
                    'success_rate',
                ],
                'settlements' => [
                    'pending_volume',
                    'completed_batches',
                ],
                'ledger_health' => [
                    'is_balanced',
                    'total_debits',
                    'total_credits',
                    'net_variance',
                    'total_entries',
                ],
                'recent_transactions',
            ],
        ]);

        $data = $response->json('data');
        $this->assertTrue($data['ledger_health']['is_balanced']);
        $this->assertEquals(0, $data['ledger_health']['net_variance']);
    }

    public function test_analytics_summary_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/analytics/summary');

        $response->assertStatus(401);
    }
}
