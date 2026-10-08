<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Transaction;
use App\Services\Gateway\Adapters\FlutterwavePaymentGateway;
use App\Services\Gateway\Adapters\PaystackPaymentGateway;
use App\Services\Gateway\Adapters\SimulatedPaymentGateway;
use App\Services\Gateway\Adapters\StripePaymentGateway;
use App\Services\Gateway\GatewayManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealGatewayAdaptersTest extends TestCase
{
    use DatabaseTransactions;

    protected Merchant $merchant;

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
    }

    protected function makeTransaction(int $amount = 150000, string $currency = 'NGN'): Transaction
    {
        return Transaction::create([
            'merchant_id' => $this->merchant->id,
            'reference' => 'TXN-' . strtoupper(Str::random(12)),
            'amount' => $amount,
            'fee_amount' => (int) round($amount * 0.015),
            'net_amount' => $amount - (int) round($amount * 0.015),
            'currency' => $currency,
            'status' => Transaction::STATUS_PROCESSING,
            'payment_method' => 'CARD',
            'idempotency_key' => 'IDEMP-' . Str::uuid(),
        ]);
    }

    public function test_paystack_gateway_handles_standard_approval_and_test_cards(): void
    {
        $gateway = new PaystackPaymentGateway();
        $this->assertEquals('PAYSTACK', $gateway->getName());

        $txn = $this->makeTransaction();

        // 1. Success test card (...4081)
        $resSuccess = $gateway->charge($txn, [
            'card' => ['number' => '4084084084084081', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
            'customer' => ['email' => 'customer@example.com'],
        ]);
        $this->assertTrue($resSuccess->isSuccess());
        $this->assertEquals('00', $resSuccess->approvalCode);
        $this->assertStringStartsWith('PSTK-', $resSuccess->providerReference);

        // 2. 3D Secure OTP Pending (...4082)
        $resPending = $gateway->charge($txn, [
            'card' => ['number' => '4084084084084082', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
        ]);
        $this->assertTrue($resPending->isPending());
        $this->assertEquals('02', $resPending->approvalCode);

        // 3. Insufficient Funds (...4083)
        $resDeclined = $gateway->charge($txn, [
            'card' => ['number' => '4084084084084083', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
        ]);
        $this->assertTrue($resDeclined->isFailed());
        $this->assertFalse($resDeclined->isRetryable);
        $this->assertEquals('PSTK_INSUFFICIENT_FUNDS', $resDeclined->errorCode);

        // 4. Switch Timeout Retryable (...4085)
        $resSwitch = $gateway->charge($txn, [
            'card' => ['number' => '4084084084084085', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
        ]);
        $this->assertTrue($resSwitch->isFailed());
        $this->assertTrue($resSwitch->isRetryable);
    }

    public function test_flutterwave_gateway_handles_standard_approval_and_test_cards(): void
    {
        $gateway = new FlutterwavePaymentGateway();
        $this->assertEquals('FLUTTERWAVE', $gateway->getName());

        $txn = $this->makeTransaction();

        // 1. Success card (...0001)
        $resSuccess = $gateway->charge($txn, [
            'card' => ['number' => '5438891000000001', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
            'customer' => ['email' => 'customer@example.com'],
        ]);
        $this->assertTrue($resSuccess->isSuccess());
        $this->assertEquals('00', $resSuccess->approvalCode);
        $this->assertStringStartsWith('FLW-', $resSuccess->providerReference);

        // 2. PIN / OTP Required (...0002)
        $resPending = $gateway->charge($txn, [
            'card' => ['number' => '5438891000000002', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
        ]);
        $this->assertTrue($resPending->isPending());
        $this->assertEquals('02', $resPending->approvalCode);

        // 3. Switch Timeout Retryable (...0091)
        $resSwitch = $gateway->charge($txn, [
            'card' => ['number' => '5438891000000091', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
        ]);
        $this->assertTrue($resSwitch->isFailed());
        $this->assertTrue($resSwitch->isRetryable);
    }

    public function test_stripe_gateway_handles_standard_payment_intents_and_test_cards(): void
    {
        $gateway = new StripePaymentGateway();
        $this->assertEquals('STRIPE', $gateway->getName());

        $txn = $this->makeTransaction(25000, 'USD');

        // 1. Succeeded card (...4242)
        $resSuccess = $gateway->charge($txn, [
            'card' => ['number' => '4242424242424242', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
        ]);
        $this->assertTrue($resSuccess->isSuccess());
        $this->assertEquals('00', $resSuccess->approvalCode);
        $this->assertStringStartsWith('pi_', $resSuccess->providerReference);

        // 2. Requires action 3DS (...0002)
        $resPending = $gateway->charge($txn, [
            'card' => ['number' => '4000000000000002', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
        ]);
        $this->assertTrue($resPending->isPending());
        $this->assertEquals('02', $resPending->approvalCode);

        // 3. Card declined insufficient funds (...0051)
        $resDeclined = $gateway->charge($txn, [
            'card' => ['number' => '4000000000000051', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
        ]);
        $this->assertTrue($resDeclined->isFailed());
        $this->assertFalse($resDeclined->isRetryable);

        // 4. Processing error retryable (...0091)
        $resRetry = $gateway->charge($txn, [
            'card' => ['number' => '4000000000000091', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
        ]);
        $this->assertTrue($resRetry->isFailed());
        $this->assertTrue($resRetry->isRetryable);
    }

    public function test_gateway_manager_dynamically_routes_to_preferred_gateway_and_fails_over(): void
    {
        $manager = new GatewayManager();

        // 1. Resolve gateways by identifier
        $this->assertInstanceOf(PaystackPaymentGateway::class, $manager->resolveGateway('PAYSTACK'));
        $this->assertInstanceOf(FlutterwavePaymentGateway::class, $manager->resolveGateway('FLUTTERWAVE'));
        $this->assertInstanceOf(StripePaymentGateway::class, $manager->resolveGateway('STRIPE'));
        $this->assertInstanceOf(SimulatedPaymentGateway::class, $manager->resolveGateway('SIMULATED_PRIMARY'));

        // 2. Execute charge directed to Paystack preferred route
        $txn = $this->makeTransaction();
        $response = $manager->executeCharge($txn, [
            'preferred_gateway' => 'PAYSTACK',
            'card' => ['number' => '4084084084084081', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
            'customer' => ['email' => 'preferred@example.com'],
        ]);

        $this->assertTrue($response->isSuccess());
        $this->assertEquals('PAYSTACK', $response->provider);

        // 3. Automatic failover: Paystack returns retryable switch timeout (4085) -> failover to secondary route
        $txnFailover = $this->makeTransaction();
        $responseFailover = $manager->executeCharge($txnFailover, [
            'preferred_gateway' => 'PAYSTACK',
            'card' => ['number' => '4084084084084085', 'cvv' => '123', 'exp_month' => '12', 'exp_year' => '2028'],
            'customer' => ['email' => 'failover@example.com'],
        ]);

        // Attempt 1 was PAYSTACK (failed switch), Attempt 2 was secondary fallback (success)
        $this->assertEquals(2, $txnFailover->paymentAttempts()->count());
        $this->assertTrue($responseFailover->isSuccess());
    }
}
