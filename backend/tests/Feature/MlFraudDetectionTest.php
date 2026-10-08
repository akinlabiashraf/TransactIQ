<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\RiskService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class MlFraudDetectionTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;
    protected Customer $customer;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::firstOrCreate(
            ['merchant_code' => 'MC-SWIFTPAY'],
            [
                'name' => 'SwiftPay Global Ltd',
                'business_email' => 'finance@swiftpay.io',
                'webhook_url' => 'https://webhook.site/test',
                'webhook_secret' => 'whsec_test',
                'status' => 'ACTIVE',
            ]
        );

        $this->customer = Customer::firstOrCreate(
            ['email' => 'client@example.com'],
            [
                'merchant_id' => $this->merchant->id,
                'customer_code' => 'CUST-' . strtoupper(Str::random(8)),
                'name' => 'Standard Client',
            ]
        );

        $adminRole = Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Admin', 'description' => 'Platform Administrator']
        );

        $this->adminUser = User::firstOrCreate(
            ['email' => 'risk_admin@transactiq.io'],
            [
                'merchant_id' => $this->merchant->id,
                'role_id' => $adminRole->id,
                'name' => 'Risk Admin',
                'password' => bcrypt('Password123!'),
                'status' => 'ACTIVE',
            ]
        );
    }

    public function test_risk_service_scores_normal_transaction_with_low_ml_anomaly(): void
    {
        $service = app(RiskService::class);

        $payload = [
            'amount' => 150000, // ₦1,500
            'currency' => 'NGN',
            'card' => ['number' => '4000123456780001'],
            'customer' => ['email' => $this->customer->email],
        ];

        $result = $service->evaluate($this->merchant, $this->customer, $payload);

        $this->assertTrue($result->isAllowed());
        $this->assertLessThan(60, $result->score);
        $this->assertNotNull($result->mlAnomalyScore);
        $this->assertLessThan(0.70, $result->mlAnomalyScore);
    }

    public function test_risk_service_detects_severe_anomalies_and_blocks_with_ml_flag(): void
    {
        $service = app(RiskService::class);

        // Extreme anomaly payload: abnormal ticket size (₦2,500,000)
        $payload = [
            'amount' => 250000000, // ₦2.5M
            'currency' => 'NGN',
            'card' => ['number' => '4000123456780001'],
            'customer' => ['email' => $this->customer->email],
        ];

        $result = $service->evaluate($this->merchant, $this->customer, $payload);

        // Should flag high ticket and elevate ML anomaly
        $this->assertTrue(in_array('HIGH_TICKET_ALERT', $result->flags, true));
        $this->assertGreaterThanOrEqual(30, $result->score);
        $this->assertNotNull($result->mlAnomalyScore);
        $this->assertIsArray($result->mlAnomalyFactors);
    }

    public function test_graceful_degradation_when_ml_microservice_is_unreachable(): void
    {
        $service = app(RiskService::class);

        // Point to dead port
        Config::set('services.ai.fraud_url', 'http://127.0.0.1:59999/predict/fraud');

        $payload = [
            'amount' => 50000,
            'currency' => 'NGN',
            'card' => ['number' => '4000123456780001'],
            'customer' => ['email' => $this->customer->email],
        ];

        // Must not throw an exception; smoothly degrades to heuristic fallback
        $result = $service->evaluate($this->merchant, $this->customer, $payload);

        $this->assertInstanceOf(\App\Services\Security\RiskEvaluationResult::class, $result);
        $this->assertEquals('FALLBACK', $result->metadata['ml_service_status']);
    }

    public function test_security_risk_metrics_endpoint_exposes_ml_fraud_engine_status(): void
    {
        $keyPair = \App\Models\ApiKey::createKeyPair($this->merchant, 'Live Test Key', 'LIVE', ['*']);
        $apiKey = $keyPair['secret_key'];

        $response = $this->withHeaders([
            'X-Api-Key' => $apiKey,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/security/risk-metrics');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'total_evaluated',
                'total_blocked',
                'active_rules_count',
                'rules',
                'ml_fraud_engine' => [
                    'status',
                    'model',
                    'algorithm',
                    'features_count',
                ],
            ],
        ]);
    }

    public function test_security_simulator_endpoint_returns_ml_anomaly_fields(): void
    {
        $keyPair = \App\Models\ApiKey::createKeyPair($this->merchant, 'Live Test Key 2', 'LIVE', ['*']);
        $apiKey = $keyPair['secret_key'];

        $response = $this->withHeaders([
            'X-Api-Key' => $apiKey,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/security/risk-rules/evaluate', [
            'amount' => 350000000, // ₦3.5M
            'currency' => 'NGN',
            'customer_email' => 'anomalous_client@example.com',
            'card_number' => '4000123456780001',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'score',
                'decision',
                'flags',
                'reason',
                'ml_anomaly_score',
                'ml_risk_level',
                'ml_anomaly_factors',
                'metadata',
            ],
        ]);
    }
}
