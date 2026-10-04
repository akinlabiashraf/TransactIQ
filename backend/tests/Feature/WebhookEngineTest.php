<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhookJob;
use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Services\Webhook\WebhookDispatcherService;
use App\Services\Webhook\WebhookSignerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookEngineTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;
    protected string $secretKey;
    protected WebhookSignerService $signer;
    protected WebhookDispatcherService $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->firstOrFail();

        $keyPair = ApiKey::createKeyPair($this->merchant, 'Webhook Test Key', 'TEST', ['payments:read', 'payments:write']);
        $this->secretKey = $keyPair['secret_key'];

        $this->signer = app(WebhookSignerService::class);
        $this->dispatcher = app(WebhookDispatcherService::class);
    }

    public function test_hmac_sha256_signature_generation_and_verification(): void
    {
        $payload = ['event' => 'payment.success', 'data' => ['amount' => 500000]];
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $secret = 'whsec_test_secret_123456789';

        $sigInfo = $this->signer->generateSignature($payload, $secret);

        $this->assertStringStartsWith('t=', $sigInfo['header']);
        $this->assertStringContainsString(',v1=', $sigInfo['header']);

        // 1. Valid signature passes verification
        $isValid = $this->signer->verifySignature($jsonPayload, $sigInfo['header'], $secret);
        $this->assertTrue($isValid);

        // 2. Tampered payload fails verification
        $tamperedJson = json_encode(['event' => 'payment.success', 'data' => ['amount' => 999999]]);
        $isTamperedValid = $this->signer->verifySignature($tamperedJson, $sigInfo['header'], $secret);
        $this->assertFalse($isTamperedValid);

        // 3. Expired timestamp fails tolerance check
        $expiredSig = $this->signer->generateSignature($payload, $secret, time() - 600); // 10 minutes ago
        $isExpiredValid = $this->signer->verifySignature($jsonPayload, $expiredSig['header'], $secret, 300);
        $this->assertFalse($isExpiredValid);
    }

    public function test_payment_success_triggers_signed_webhook_creation_and_queue_dispatch(): void
    {
        Queue::fake();

        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 450000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'webhook.success@example.com'],
            'card' => [
                'number' => '4000 0000 0000 0001',
                'exp_month' => '11',
                'exp_year' => '2028',
                'cvv' => '123',
            ],
        ]);

        $response->assertStatus(201);
        $txnId = $response->json('data.id');

        // Check WebhookDelivery created in DB
        $delivery = WebhookDelivery::where('transaction_id', $txnId)->first();
        $this->assertNotNull($delivery);
        $this->assertEquals($this->merchant->id, $delivery->merchant_id);
        $this->assertEquals('payment.success', $delivery->event_type);
        $this->assertEquals(WebhookDelivery::STATUS_PENDING, $delivery->status);
        $this->assertStringStartsWith('t=', $delivery->signature);
        $this->assertEquals(450000, $delivery->payload['data']['amount']);

        // Assert job was queued
        Queue::assertPushed(DispatchWebhookJob::class, function ($job) use ($delivery) {
            return $job->deliveryId === $delivery->id;
        });
    }

    public function test_payment_failure_triggers_payment_failed_webhook(): void
    {
        Queue::fake();

        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 600000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'webhook.failed@example.com'],
            'card' => [
                'number' => '4000 0000 0000 0051', // Insufficient funds
                'exp_month' => '07',
                'exp_year' => '2027',
                'cvv' => '456',
            ],
        ]);

        $response->assertStatus(201);
        $txnId = $response->json('data.id');

        $delivery = WebhookDelivery::where('transaction_id', $txnId)->first();
        $this->assertNotNull($delivery);
        $this->assertEquals('payment.failed', $delivery->event_type);
        $this->assertEquals(WebhookDelivery::STATUS_PENDING, $delivery->status);

        Queue::assertPushed(DispatchWebhookJob::class);
    }

    public function test_webhook_delivery_success_lifecycle(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'Event acknowledged', 'code' => 200], 200),
        ]);

        $delivery = WebhookDelivery::create([
            'merchant_id' => $this->merchant->id,
            'event_type' => 'payment.success',
            'endpoint_url' => 'https://merchant.example.com/webhooks',
            'signature' => 't=' . time() . ',v1=test_signature',
            'payload' => ['event' => 'payment.success', 'data' => ['ref' => 'TXN-TEST']],
            'attempts' => 0,
            'max_attempts' => 5,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $delivered = $this->dispatcher->deliver($delivery);

        $this->assertTrue($delivered);

        $delivery->refresh();
        $this->assertEquals(WebhookDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertEquals(1, $delivery->attempts);
        $this->assertEquals(200, $delivery->response_status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNull($delivery->next_retry_at);
    }

    public function test_webhook_delivery_failure_triggers_exponential_backoff(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Gateway Unavailable'], 503),
        ]);

        $delivery = WebhookDelivery::create([
            'merchant_id' => $this->merchant->id,
            'event_type' => 'payment.success',
            'endpoint_url' => 'https://merchant.example.com/failing-endpoint',
            'signature' => 't=' . time() . ',v1=test_signature',
            'payload' => ['event' => 'payment.success', 'data' => ['ref' => 'TXN-FAIL']],
            'attempts' => 0,
            'max_attempts' => 5,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $delivered = $this->dispatcher->deliver($delivery);

        $this->assertFalse($delivered);

        $delivery->refresh();
        $this->assertEquals(WebhookDelivery::STATUS_RETRYING, $delivery->status);
        $this->assertEquals(1, $delivery->attempts);
        $this->assertEquals(503, $delivery->response_status);
        $this->assertNotNull($delivery->next_retry_at);
        $this->assertGreaterThan(now()->toDateTimeString(), $delivery->next_retry_at->toDateTimeString());
    }

    public function test_webhook_terminal_failure_after_max_attempts(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Permanent Server Error'], 500),
        ]);

        $delivery = WebhookDelivery::create([
            'merchant_id' => $this->merchant->id,
            'event_type' => 'payment.failed',
            'endpoint_url' => 'https://merchant.example.com/failing-endpoint',
            'signature' => 't=' . time() . ',v1=test_signature',
            'payload' => ['event' => 'payment.failed'],
            'attempts' => 4, // 5th attempt will be terminal
            'max_attempts' => 5,
            'status' => WebhookDelivery::STATUS_RETRYING,
        ]);

        $delivered = $this->dispatcher->deliver($delivery);

        $this->assertFalse($delivered);

        $delivery->refresh();
        $this->assertEquals(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertEquals(5, $delivery->attempts);
        $this->assertNull($delivery->next_retry_at);
    }

    public function test_manual_webhook_replay_endpoint(): void
    {
        Queue::fake();

        $delivery = WebhookDelivery::create([
            'merchant_id' => $this->merchant->id,
            'event_type' => 'payment.success',
            'endpoint_url' => 'https://merchant.example.com/webhooks',
            'signature' => 't=' . time() . ',v1=test_signature',
            'payload' => ['event' => 'payment.success'],
            'attempts' => 5,
            'max_attempts' => 5,
            'status' => WebhookDelivery::STATUS_FAILED,
            'response_status' => 500,
        ]);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->postJson("/api/v1/webhooks/{$delivery->id}/replay");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', WebhookDelivery::STATUS_PENDING)
            ->assertJsonPath('data.attempts', 0);

        Queue::assertPushed(DispatchWebhookJob::class);
    }

    public function test_merchant_can_list_and_filter_their_webhooks(): void
    {
        WebhookDelivery::create([
            'merchant_id' => $this->merchant->id,
            'event_type' => 'payment.success',
            'endpoint_url' => 'https://merchant.example.com/webhooks',
            'signature' => 't=' . time() . ',v1=test_signature',
            'payload' => ['event' => 'payment.success'],
            'attempts' => 1,
            'max_attempts' => 5,
            'status' => WebhookDelivery::STATUS_DELIVERED,
            'response_status' => 200,
        ]);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->getJson('/api/v1/webhooks?status=DELIVERED');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => [
                    '*' => [
                        'id',
                        'event_type',
                        'endpoint_url',
                        'signature',
                        'attempts',
                        'status',
                        'created_at',
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data'));
    }

    public function test_loopback_test_receiver_endpoint(): void
    {
        $response = $this->withHeaders([
            'X-TransactIQ-Signature' => 't=1727078400,v1=test_sig',
        ])->postJson('/api/v1/webhooks/test-endpoint', [
            'event_type' => 'payment.success',
            'data' => ['test' => true],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'received')
            ->assertJsonPath('received_event', 'payment.success')
            ->assertJsonPath('received_signature', 't=1727078400,v1=test_sig');
    }
}
