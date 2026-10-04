<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthAndSecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->firstOrFail();
    }

    public function test_user_can_login_with_valid_credentials_and_receive_sanctum_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@transactiq.io',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user' => ['id', 'name', 'email', 'role', 'role_name'],
                ],
            ]);

        $this->assertEquals('admin', $response->json('data.user.role'));
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@transactiq.io',
            'password' => 'WrongPassword999!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error', 'invalid_credentials');
    }

    public function test_authenticated_user_can_access_me_and_logout(): void
    {
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'ops@transactiq.io',
            'password' => 'Password123!',
        ]);

        $token = $loginRes->json('data.token');

        // Test GET /me
        $meRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $meRes->assertStatus(200)
            ->assertJsonPath('data.email', 'ops@transactiq.io')
            ->assertJsonPath('data.role', 'operations');

        // Test POST /logout
        $logoutRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout');

        $logoutRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // Reset in-memory guard cache to test fresh token validation
        auth()->forgetGuards();

        // Subsequent access with revoked token must fail
        $revokedRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $revokedRes->assertStatus(401);
    }

    public function test_idempotency_returns_cached_transaction_when_payload_matches(): void
    {
        $keyPair = ApiKey::createKeyPair($this->merchant, 'Idemp Test Key', 'TEST', ['*']);
        $secretKey = $keyPair['secret_key'];
        $idempotencyKey = 'idemp-' . Str::random(16);

        $payload = [
            'amount' => 500000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'idemp.match@example.com', 'name' => 'John Doe'],
            'card' => ['number' => '4000 0000 0000 0001', 'exp_month' => '12', 'exp_year' => '2028', 'cvv' => '123'],
        ];

        // 1. Initial execution
        $res1 = $this->withHeaders([
            'X-Api-Key' => $secretKey,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/payments', $payload);

        $res1->assertStatus(201);
        $reference = $res1->json('data.reference');

        // 2. Exact duplicate submission (must replay cleanly)
        $res2 = $this->withHeaders([
            'X-Api-Key' => $secretKey,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/payments', $payload);

        $res2->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('data.reference', $reference);
    }

    public function test_idempotency_rejects_with_422_when_payload_is_modified(): void
    {
        $keyPair = ApiKey::createKeyPair($this->merchant, 'Idemp Tamper Key', 'TEST', ['*']);
        $secretKey = $keyPair['secret_key'];
        $idempotencyKey = 'idemp-' . Str::random(16);

        $originalPayload = [
            'amount' => 500000, // ₦5,000.00
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'tamper.test@example.com'],
            'card' => ['number' => '4000 0000 0000 0001', 'exp_month' => '12', 'exp_year' => '2028', 'cvv' => '123'],
        ];

        // 1. Initial submission succeeds
        $res1 = $this->withHeaders([
            'X-Api-Key' => $secretKey,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/payments', $originalPayload);

        $res1->assertStatus(201);

        // 2. Replay with altered amount (e.g. ₦50,000.00 instead of ₦5,000.00)
        $tamperedPayload = $originalPayload;
        $tamperedPayload['amount'] = 5000000;

        $res2 = $this->withHeaders([
            'X-Api-Key' => $secretKey,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/payments', $tamperedPayload);

        $res2->assertStatus(422);
    }

    public function test_rbac_enforcement_on_sensitive_audit_logs(): void
    {
        // 1. Admin user allowed
        $admin = User::where('email', 'admin@transactiq.io')->first();
        $adminToken = $admin->createToken('admin-test', ['admin'])->plainTextToken;

        $resAdmin = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/v1/security/audit-logs');
        $resAdmin->assertStatus(200);

        // 2. Auditor user allowed
        $auditor = User::where('email', 'auditor@transactiq.io')->first();
        $auditorToken = $auditor->createToken('auditor-test', ['auditor'])->plainTextToken;

        $resAuditor = $this->withHeader('Authorization', 'Bearer ' . $auditorToken)
            ->getJson('/api/v1/security/audit-logs');
        $resAuditor->assertStatus(200);

        // 3. Regular merchant API key blocked (403 Forbidden)
        auth()->forgetGuards();
        $merchantKey = ApiKey::createKeyPair($this->merchant, 'Merchant Scoped Key', 'TEST', ['payments:read', 'payments:write']);
        $resMerchant = $this->withHeader('X-Api-Key', $merchantKey['secret_key'])
            ->getJson('/api/v1/security/audit-logs');
        $resMerchant->assertStatus(403);
    }

    public function test_rbac_blocks_auditor_from_initiating_payments(): void
    {
        $auditor = User::where('email', 'auditor@transactiq.io')->first();
        $auditorToken = $auditor->createToken('auditor-write-test', ['auditor'])->plainTextToken;

        $payload = [
            'amount' => 100000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => ['email' => 'auditor.blocked@example.com'],
            'card' => ['number' => '4000 0000 0000 0001', 'exp_month' => '12', 'exp_year' => '2028', 'cvv' => '123'],
        ];

        $res = $this->withHeaders([
            'Authorization' => 'Bearer ' . $auditorToken,
            'Idempotency-Key' => 'idemp-' . Str::random(16),
        ])->postJson('/api/v1/payments', $payload);

        $res->assertStatus(403);
    }
}
