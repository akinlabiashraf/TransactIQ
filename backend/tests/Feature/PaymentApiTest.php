<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;
    protected string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->firstOrFail();

        // Create a known test API key for testing
        $keyPair = ApiKey::createKeyPair($this->merchant, 'Test Suite Key', 'TEST', ['payments:read', 'payments:write']);
        $this->secretKey = $keyPair['secret_key'];
    }

    public function test_missing_api_key_returns_401(): void
    {
        $response = $this->postJson('/api/v1/payments', [
            'amount' => 500000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'jane@example.com'],
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized');
    }

    public function test_invalid_api_key_returns_401(): void
    {
        $response = $this->withHeaders([
            'X-Api-Key' => 'tiq_test_sec_invalidkey1234567890abcdef',
            'Idempotency-Key' => 'idemp-' . Str::random(12),
        ])->postJson('/api/v1/payments', [
            'amount' => 500000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'jane@example.com'],
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized');
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 500000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'jane@example.com'],
        ]);

        $response->assertStatus(422);
    }

    public function test_valid_payment_creation_returns_201_with_fsm_history(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 1000000, // ₦10,000.00
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => [
                'email' => 'amara.okafor@example.com',
                'name' => 'Amara Okafor',
            ],
            'metadata' => ['order_id' => 'ORD-98765'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'id',
                    'reference',
                    'amount',
                    'fee_amount',
                    'net_amount',
                    'currency',
                    'status',
                    'idempotency_key',
                    'customer' => ['id', 'email', 'name'],
                    'events',
                    'payment_attempts',
                ],
            ]);

        $ref = $response->json('data.reference');
        $this->assertStringStartsWith('TXN-', $ref);
        $this->assertEquals(Transaction::STATUS_SUCCESS, $response->json('data.status'));

        // Verify fee calculation (1.5% of ₦10,000 + ₦100 flat = ₦150 + ₦100 = ₦250 -> 25000 kobo)
        $this->assertEquals(1000000, $response->json('data.amount'));
        $this->assertGreaterThan(0, $response->json('data.fee_amount'));

        // Verify events audit log
        $events = $response->json('data.events');
        $this->assertNotEmpty($events);
        $this->assertEquals('SUCCESS', end($events)['to_status']);
    }

    public function test_idempotent_duplicate_request_returns_cached_200_without_duplication(): void
    {
        $idempKey = 'idemp-' . Str::random(16);
        $payload = [
            'amount' => 750000, // ₦7,500.00
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'tunde.bakare@example.com'],
        ];

        // First execution -> 201 Created
        $firstResponse = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', $payload);

        $firstResponse->assertStatus(201);
        $originalRef = $firstResponse->json('data.reference');

        // Second execution with SAME Idempotency-Key -> 200 OK replay
        $secondResponse = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', $payload);

        $secondResponse->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('data.reference', $originalRef);

        // Crucial Invariant: Exactly 1 record exists in database
        $this->assertEquals(1, Transaction::where('merchant_id', $this->merchant->id)->where('idempotency_key', $idempKey)->count());
    }

    public function test_fetch_payment_by_reference(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $createRes = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 2000000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'query.test@example.com'],
        ]);

        $ref = $createRes->json('data.reference');

        $queryRes = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
        ])->getJson("/api/v1/payments/{$ref}");

        $queryRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reference', $ref)
            ->assertJsonPath('data.amount', 2000000);
    }

    public function test_failed_simulation_outcome(): void
    {
        $idempKey = 'idemp-' . Str::random(16);

        $response = $this->withHeaders([
            'X-Api-Key' => $this->secretKey,
            'Idempotency-Key' => $idempKey,
        ])->postJson('/api/v1/payments', [
            'amount' => 300000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'declined.user@example.com'],
            'gateway_simulation' => 'FAILED',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', Transaction::STATUS_FAILED)
            ->assertJsonPath('data.failure_reason', 'Card declined by issuing bank');
    }
}
