<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class GatewaySimulationTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;
    protected string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->firstOrFail();

        $keyPair = ApiKey::createKeyPair($this->merchant, 'Gateway Test Key', 'TEST', ['payments:read', 'payments:write']);
        $this->secretKey = $keyPair['secret_key'];
    }

    public function test_instant_approved_card_produces_success_and_one_attempt(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 500000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'card.success@example.com'],
            'card' => [
                'number' => '4000 0000 0000 0001',
                'exp_month' => '12',
                'exp_year' => '2028',
                'cvv' => '123',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.provider', 'SIMULATED_PRIMARY');

        $txnId = $response->json('data.id');
        $attempts = PaymentAttempt::where('transaction_id', $txnId)->orderBy('attempt_number')->get();

        $this->assertCount(1, $attempts);
        $this->assertEquals(1, $attempts[0]->attempt_number);
        $this->assertEquals('SIMULATED_PRIMARY', $attempts[0]->provider);
        $this->assertEquals('SUCCESS', $attempts[0]->status);
        $this->assertEquals('4000 •••• •••• 0001', $attempts[0]->gateway_request['masked_card']);
        $this->assertEquals('00', $attempts[0]->gateway_response['approval_code']);
    }

    public function test_insufficient_funds_card_produces_failed_status_with_decline_code(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 500000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'card.declined@example.com'],
            'card' => [
                'number' => '4000 0000 0000 0051',
                'exp_month' => '08',
                'exp_year' => '2027',
                'cvv' => '456',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'FAILED');

        $txnId = $response->json('data.id');
        $attempt = PaymentAttempt::where('transaction_id', $txnId)->firstOrFail();

        $this->assertEquals('FAILED', $attempt->status);
        $this->assertEquals('DECLINED_51', $attempt->error_code);
        $this->assertStringContainsString('Insufficient funds', $attempt->error_message);
    }

    public function test_expired_card_produces_failed_status_with_card_expired_code(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 350000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'card.expired@example.com'],
            'card' => [
                'number' => '4000 0000 0000 0033',
                'exp_month' => '01',
                'exp_year' => '2022',
                'cvv' => '789',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'FAILED');

        $txnId = $response->json('data.id');
        $attempt = PaymentAttempt::where('transaction_id', $txnId)->firstOrFail();

        $this->assertEquals('FAILED', $attempt->status);
        $this->assertEquals('CARD_EXPIRED_33', $attempt->error_code);
    }

    public function test_issuer_down_triggers_automatic_fallback_retry_and_logs_two_attempts(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 950000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'failover.test@example.com'],
            'card' => [
                'number' => '4000 0000 0000 0091',
                'exp_month' => '05',
                'exp_year' => '2029',
                'cvv' => '999',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.provider', 'SIMULATED_FALLBACK');

        $txnId = $response->json('data.id');
        $attempts = PaymentAttempt::where('transaction_id', $txnId)->orderBy('attempt_number')->get();

        // Must record 2 attempts: Attempt 1 Failed on Primary, Attempt 2 Succeeded on Fallback
        $this->assertCount(2, $attempts);

        $this->assertEquals(1, $attempts[0]->attempt_number);
        $this->assertEquals('SIMULATED_PRIMARY', $attempts[0]->provider);
        $this->assertEquals('FAILED', $attempts[0]->status);
        $this->assertEquals('BANK_ISSUER_DOWN_91', $attempts[0]->error_code);

        $this->assertEquals(2, $attempts[1]->attempt_number);
        $this->assertEquals('SIMULATED_FALLBACK', $attempts[1]->provider);
        $this->assertEquals('SUCCESS', $attempts[1]->status);
        $this->assertEquals('00', $attempts[1]->gateway_response['approval_code']);

        // Verify that GATEWAY_FAILOVER_INITIATED event was logged
        $txn = Transaction::with('events')->find($txnId);
        $failoverEvent = $txn->events->firstWhere('event_type', 'GATEWAY_FAILOVER_INITIATED');
        $this->assertNotNull($failoverEvent);
        $this->assertEquals('GATEWAY_MANAGER', $failoverEvent->triggered_by);
    }

    public function test_otp_required_card_produces_pending_status(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 1200000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'otp.test@example.com'],
            'card' => [
                'number' => '4000 0000 0000 0002',
                'exp_month' => '10',
                'exp_year' => '2027',
                'cvv' => '321',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING');

        $txnId = $response->json('data.id');
        $attempt = PaymentAttempt::where('transaction_id', $txnId)->firstOrFail();

        $this->assertEquals('PENDING', $attempt->status);
        $this->assertArrayHasKey('auth_url', $attempt->gateway_response);
    }

    public function test_gateway_timeout_simulation_produces_pending_status(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 800000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'timeout.test@example.com'],
            'card' => [
                'number' => '4000 0000 0000 0504',
                'exp_month' => '03',
                'exp_year' => '2026',
                'cvv' => '504',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING');

        $txnId = $response->json('data.id');
        $attempt = PaymentAttempt::where('transaction_id', $txnId)->firstOrFail();

        $this->assertEquals('GATEWAY_TIMEOUT_504', $attempt->error_code);
    }

    public function test_backward_compatible_gateway_simulation_flag(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        // Test flag: FAILED
        $responseFailed = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 200000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'legacy.flag@example.com'],
            'gateway_simulation' => 'FAILED',
        ]);

        $responseFailed->assertStatus(201)
            ->assertJsonPath('data.status', 'FAILED');

        // Test flag: SUCCESS
        $responseSuccess = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => 'idemp-' . Str::random(16),
        ])->postJson('/api/v1/payments', [
            'amount' => 200000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'legacy.success@example.com'],
            'gateway_simulation' => 'SUCCESS',
        ]);

        $responseSuccess->assertStatus(201)
            ->assertJsonPath('data.status', 'SUCCESS');
    }
}
