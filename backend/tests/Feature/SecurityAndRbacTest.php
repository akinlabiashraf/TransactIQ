<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckRole;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Security\AuditLogService;
use App\Services\Security\RiskEvaluationResult;
use App\Services\Security\RiskService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class SecurityAndRbacTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;
    protected string $liveSecretKey;
    protected AuditLogService $auditLogService;
    protected RiskService $riskService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::where('merchant_code', 'MC-SWIFTPAY')->first();
        AuditLogService::resetChainCache();
        $this->auditLogService = app(AuditLogService::class);
        $this->riskService = app(RiskService::class);

        $apiKey = ApiKey::createKeyPair($this->merchant, 'Security Test Key', 'LIVE', ['*']);
        $this->liveSecretKey = $apiKey['secret_key'];
    }

    public function test_audit_log_service_creates_records_with_sha256_integrity_hash(): void
    {
        $log = $this->auditLogService->logMutation(
            action: 'SECURITY_POLICY_UPDATE',
            entityType: 'SecurityPolicy',
            entityId: 'pol_12345',
            oldValues: ['velocity_limit' => 3],
            newValues: ['velocity_limit' => 5],
            actorType: 'OPERATIONS'
        );

        $this->assertNotNull($log->id);
        $this->assertEquals('SECURITY_POLICY_UPDATE', $log->action);
        $this->assertArrayHasKey('_integrity', $log->new_values);
        $this->assertNotEmpty($log->new_values['_integrity']['hash']);

        // Verify cryptographic integrity check succeeds
        $this->assertTrue($this->auditLogService->verifyRecordIntegrity($log));

        // Tamper test: if values are modified out-of-band, verification must fail
        $tamperedLog = clone $log;
        $tamperedLog->action = 'MALICIOUS_OVERRIDE';
        $this->assertFalse($this->auditLogService->verifyRecordIntegrity($tamperedLog));
    }

    public function test_audit_log_service_scrubs_pci_dss_sensitive_fields(): void
    {
        $sensitiveData = [
            'card_number' => '4000123456780001',
            'cvv' => '999',
            'api_secret' => 'tiq_live_sec_abcdef1234567890abcdef',
            'password' => 'secret_password_123',
            'customer_name' => 'Alice Doe',
        ];

        $scrubbed = $this->auditLogService->scrubSensitiveData($sensitiveData);

        // PAN must be masked
        $this->assertStringContainsString('400012', $scrubbed['card_number']);
        $this->assertStringContainsString('0001', $scrubbed['card_number']);
        $this->assertStringContainsString('******', $scrubbed['card_number']);

        // CVV and password must never be stored
        $this->assertEquals('***', $scrubbed['cvv']);
        $this->assertEquals('***', $scrubbed['password']);

        // Secret keys must be masked
        $this->assertStringContainsString('tiq_live_sec', $scrubbed['api_secret']);
        $this->assertStringContainsString('****', $scrubbed['api_secret']);

        // Safe fields preserved
        $this->assertEquals('Alice Doe', $scrubbed['customer_name']);
    }

    public function test_audit_chain_verification_maintains_unbroken_integrity(): void
    {
        // Generate a sequence of 3 linked audit records
        $log1 = $this->auditLogService->logMutation('ACTION_ONE', 'TypeA', '101', null, ['step' => 1]);
        $log2 = $this->auditLogService->logMutation('ACTION_TWO', 'TypeA', '102', null, ['step' => 2]);
        $log3 = $this->auditLogService->logMutation('ACTION_THREE', 'TypeA', '103', null, ['step' => 3]);

        $this->assertEquals($log1->new_values['_integrity']['hash'], $log2->new_values['_integrity']['prev_hash']);
        $this->assertEquals($log2->new_values['_integrity']['hash'], $log3->new_values['_integrity']['prev_hash']);

        $chainResult = $this->auditLogService->verifyChainIntegrity();

        $this->assertTrue($chainResult['is_chain_healthy']);
        $this->assertGreaterThanOrEqual(3, $chainResult['total_records']);
        $this->assertEquals($chainResult['total_records'], $chainResult['valid_records']);
        $this->assertEmpty($chainResult['corrupted_records']);
    }

    public function test_role_based_access_control_user_models(): void
    {
        $admin = User::where('email', 'admin@transactiq.io')->first();
        $ops = User::where('email', 'ops@transactiq.io')->first();
        $auditor = User::where('email', 'auditor@transactiq.io')->first();
        $merchantUser = User::where('email', 'admin@swiftpay.com')->first();

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isMerchant());

        $this->assertTrue($ops->isOperations());
        $this->assertFalse($ops->isAdmin());

        $this->assertTrue($auditor->isAuditor());
        $this->assertFalse($auditor->isOperations());

        $this->assertTrue($merchantUser->isMerchant());
        $this->assertFalse($merchantUser->isAdmin());
    }

    public function test_check_role_middleware_enforces_access_policies(): void
    {
        $middleware = new CheckRole();

        // 1. Admin accessing restricted operations route: ALLOWED
        $admin = User::where('email', 'admin@transactiq.io')->first();
        $request = Request::create('/test-ops', 'GET');
        $request->setUserResolver(fn () => $admin);

        $response = $middleware->handle($request, fn () => response()->json(['status' => 'passed']), 'operations');
        $this->assertEquals(200, $response->getStatusCode());

        // 2. Auditor attempting write action restricted to admin: REJECTED (403)
        $auditor = User::where('email', 'auditor@transactiq.io')->first();
        $request = Request::create('/test-admin', 'POST');
        $request->setUserResolver(fn () => $auditor);

        $response = $middleware->handle($request, fn () => response()->json(['status' => 'passed']), 'admin');
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_risk_engine_blocks_blacklisted_cards(): void
    {
        $payload = [
            'amount' => 500000,
            'card' => ['number' => '4000000000009999'], // Blacklisted ending
            'customer' => ['email' => 'fraudster@example.com'],
        ];

        $result = $this->riskService->evaluate($this->merchant, null, $payload);

        $this->assertEquals(100, $result->score);
        $this->assertTrue($result->isBlocked());
        $this->assertContains('CARD_BLACKLISTED', $result->flags);
    }

    public function test_risk_engine_detects_high_ticket_volume_anomaly(): void
    {
        $payload = [
            'amount' => 250000000, // ₦2,500,000 (> ₦2M threshold)
            'card' => ['number' => '4000000000000001'],
            'customer' => ['email' => 'corporate@example.com'],
        ];

        $result = $this->riskService->evaluate($this->merchant, null, $payload);

        $this->assertContains('HIGH_TICKET_ALERT', $result->flags);
        $this->assertGreaterThanOrEqual(30, $result->score);
    }

    public function test_transaction_service_aborts_payment_when_risk_engine_blocks(): void
    {
        $service = app(TransactionService::class);

        $payload = [
            'amount' => 75000,
            'currency' => 'NGN',
            'payment_method' => 'CARD',
            'customer' => [
                'email' => 'stolen-card-user@example.com',
                'name' => 'Bad Actor',
            ],
            'card' => [
                'number' => '5100000000008888', // Blacklisted ending 8888
                'expiry_month' => '12',
                'expiry_year' => '28',
                'cvv' => '123',
            ],
        ];

        $txn = $service->createAndProcessPayment($this->merchant, $payload, 'idemp-risk-test-1');

        $this->assertEquals(Transaction::STATUS_FAILED, $txn->status);
        $this->assertStringContainsString('Risk Engine Block', $txn->failure_reason);

        // Assert event was logged
        $this->assertDatabaseHas('transaction_events', [
            'transaction_id' => $txn->id,
            'event_type' => 'PAYMENT_BLOCKED_BY_RISK',
        ]);

        // Assert audit log was recorded
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'PAYMENT_RISK_BLOCKED',
            'entity_id' => $txn->id,
        ]);
    }

    public function test_api_security_endpoints_return_telemetry_and_audit_data(): void
    {
        // 1. Audit logs endpoint
        $resLogs = $this->withHeader('X-Api-Key', $this->liveSecretKey)
            ->getJson('/api/v1/security/audit-logs');

        $resLogs->assertStatus(200)
            ->assertJsonStructure(['status', 'data', 'meta' => ['total', 'per_page']]);

        // 2. Audit chain verify endpoint
        $resChain = $this->withHeader('X-Api-Key', $this->liveSecretKey)
            ->getJson('/api/v1/security/audit-logs/verify-chain');

        $resChain->assertStatus(200)
            ->assertJsonPath('data.is_chain_healthy', true);

        // 3. Risk telemetry metrics endpoint
        $resRisk = $this->withHeader('X-Api-Key', $this->liveSecretKey)
            ->getJson('/api/v1/security/risk-metrics');

        $resRisk->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['total_evaluated', 'rules']]);

        // 4. Roles and users endpoint
        $resRoles = $this->withHeader('X-Api-Key', $this->liveSecretKey)
            ->getJson('/api/v1/security/roles-and-users');

        $resRoles->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['roles', 'total_users']]);

        // 5. Interactive Risk Simulator endpoint
        $simPayload = [
            'amount' => 1500000,
            'currency' => 'NGN',
            'customer_email' => 'simulator@example.com',
            'card_number' => '4000000000000001',
        ];

        $resSim = $this->withHeader('X-Api-Key', $this->liveSecretKey)
            ->postJson('/api/v1/security/risk-rules/evaluate', $simPayload);

        $resSim->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['score', 'decision', 'flags']]);
    }
}
