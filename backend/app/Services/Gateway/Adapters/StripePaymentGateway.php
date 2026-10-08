<?php

namespace App\Services\Gateway\Adapters;

use App\Models\Transaction;
use App\Services\Gateway\Contracts\PaymentGatewayInterface;
use App\Services\Gateway\DTOs\GatewayResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StripePaymentGateway implements PaymentGatewayInterface
{
    public const PROVIDER_NAME = 'STRIPE';

    protected ?string $secretKey;
    protected string $baseUrl;
    protected int $timeoutSeconds;

    public function __construct(
        ?string $secretKey = null,
        ?string $baseUrl = null,
        int $timeoutSeconds = 8
    ) {
        $this->secretKey = $secretKey ?? config('services.stripe.secret_key', env('STRIPE_SECRET_KEY'));
        $this->baseUrl = rtrim($baseUrl ?? config('services.stripe.base_url', 'https://api.stripe.com/v1'), '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    /**
     * Charge customer payment method using Stripe PaymentIntents API.
     */
    public function charge(Transaction $transaction, array $paymentDetails): GatewayResponse
    {
        $startTime = microtime(true);
        $card = $paymentDetails['card'] ?? [];
        $cardNumber = preg_replace('/\D/', '', (string) ($card['number'] ?? ''));
        $maskedPan = $this->maskPan($cardNumber);

        $sanitizedRequest = [
            'amount' => $transaction->amount,
            'currency' => strtolower($transaction->currency),
            'reference' => $transaction->reference,
            'masked_card' => $maskedPan,
            'gateway_provider' => self::PROVIDER_NAME,
        ];

        if ($this->shouldExecuteLiveHttp()) {
            return $this->executeLiveCharge($transaction, $paymentDetails, $sanitizedRequest, $startTime);
        }

        return $this->simulateStripeSandbox($cardNumber, $sanitizedRequest, $paymentDetails, $startTime);
    }

    /**
     * Execute live PaymentIntent charge against Stripe REST API.
     */
    protected function executeLiveCharge(
        Transaction $transaction,
        array $paymentDetails,
        array $sanitizedRequest,
        float $startTime
    ): GatewayResponse {
        try {
            $payload = [
                'amount' => $transaction->amount, // in minor units (cents/kobo)
                'currency' => strtolower($transaction->currency),
                'confirm' => 'true',
                'description' => "TransactIQ Charge {$transaction->reference}",
                'metadata[reference]' => $transaction->reference,
                'metadata[merchant_id]' => $transaction->merchant_id,
            ];

            // Use Stripe Test Payment Method Token if supplied or pm_card_visa fallback
            $payload['payment_method'] = $paymentDetails['payment_method_id'] ?? 'pm_card_visa';
            $payload['automatic_payment_methods[enabled]'] = 'true';
            $payload['automatic_payment_methods[allow_redirects]'] = 'never';

            $response = Http::asForm()
                ->withToken($this->secretKey)
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/payment_intents", $payload);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $body = $response->json() ?? [];

            if ($response->successful() && ($body['status'] ?? '') === 'succeeded') {
                return GatewayResponse::success(
                    provider: self::PROVIDER_NAME,
                    providerReference: $body['id'] ?? ('pi_' . Str::random(24)),
                    approvalCode: '00',
                    gatewayRequest: $sanitizedRequest,
                    gatewayResponse: $body,
                    latencyMs: $latencyMs
                );
            }

            if (($body['status'] ?? '') === 'requires_action') {
                return GatewayResponse::pending(
                    provider: self::PROVIDER_NAME,
                    providerReference: $body['id'] ?? ('pi_' . Str::random(24)),
                    approvalCode: '02',
                    message: 'Stripe 3D Secure / SCA verification required.',
                    gatewayRequest: $sanitizedRequest,
                    gatewayResponse: $body,
                    latencyMs: $latencyMs
                );
            }

            $error = $body['error'] ?? [];
            $declineCode = $error['decline_code'] ?? ($error['code'] ?? 'card_declined');
            $isRetryable = $response->serverError() || in_array($declineCode, ['processing_error', 'rate_limit']);

            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'STRIPE_' . strtoupper($declineCode),
                errorMessage: $error['message'] ?? 'Stripe PaymentIntent confirmation failed.',
                providerReference: $body['id'] ?? null,
                approvalCode: '51',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: $body,
                latencyMs: $latencyMs,
                isRetryable: $isRetryable
            );
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            Log::warning('Stripe live gateway connection error: ' . $e->getMessage());

            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'STRIPE_CONNECTION_TIMEOUT',
                errorMessage: 'Stripe API connection timed out: ' . $e->getMessage(),
                gatewayRequest: $sanitizedRequest,
                latencyMs: $latencyMs,
                isRetryable: true
            );
        }
    }

    /**
     * Deterministic Stripe Sandbox Simulation.
     */
    protected function simulateStripeSandbox(
        string $cardNumber,
        array $sanitizedRequest,
        array $paymentDetails,
        float $startTime
    ): GatewayResponse {
        $latencyMs = rand(35, 75);
        $providerRef = 'pi_' . Str::random(24);
        $gatewaySimFlag = strtoupper((string) ($paymentDetails['gateway_simulation'] ?? ''));

        // Scenario 1: Requires 3DS Action (...0002 or flag PENDING)
        if (str_ends_with($cardNumber, '0002') || $gatewaySimFlag === 'PENDING') {
            return GatewayResponse::pending(
                provider: self::PROVIDER_NAME,
                providerReference: $providerRef,
                approvalCode: '02',
                message: 'Stripe 3D Secure SCA authentication required.',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'id' => $providerRef,
                    'object' => 'payment_intent',
                    'status' => 'requires_action',
                    'next_action' => [
                        'type' => 'use_stripe_sdk',
                        'stripe_js' => 'https://js.stripe.com/v3/',
                    ],
                ],
                latencyMs: $latencyMs
            );
        }

        // Scenario 2: Processing Error / Network timeout (retryable ...0091)
        if (str_ends_with($cardNumber, '0091')) {
            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'STRIPE_PROCESSING_ERROR',
                errorMessage: 'Stripe processing error: Card brand switch timeout.',
                providerReference: $providerRef,
                approvalCode: '91',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'error' => [
                        'code' => 'processing_error',
                        'decline_code' => 'processing_error',
                        'message' => 'An error occurred while processing the card.',
                    ],
                ],
                latencyMs: $latencyMs + 30,
                isRetryable: true
            );
        }

        // Scenario 3: Card Declined / Insufficient funds
        if (str_ends_with($cardNumber, '0051') || $gatewaySimFlag === 'FAILED') {
            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'STRIPE_CARD_DECLINED_INSUFFICIENT_FUNDS',
                errorMessage: 'Your card has insufficient funds.',
                providerReference: $providerRef,
                approvalCode: '51',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'error' => [
                        'code' => 'card_declined',
                        'decline_code' => 'insufficient_funds',
                        'message' => 'Your card has insufficient funds.',
                    ],
                ],
                latencyMs: $latencyMs,
                isRetryable: false
            );
        }

        // Scenario 4: Succeeded
        return GatewayResponse::success(
            provider: self::PROVIDER_NAME,
            providerReference: $providerRef,
            approvalCode: '00',
            gatewayRequest: $sanitizedRequest,
            gatewayResponse: [
                'id' => $providerRef,
                'object' => 'payment_intent',
                'amount' => $sanitizedRequest['amount'],
                'currency' => $sanitizedRequest['currency'],
                'status' => 'succeeded',
                'charges' => [
                    'data' => [
                        [
                            'id' => 'ch_' . Str::random(24),
                            'paid' => true,
                            'status' => 'succeeded',
                        ],
                    ],
                ],
            ],
            latencyMs: $latencyMs
        );
    }

    protected function shouldExecuteLiveHttp(): bool
    {
        return !empty($this->secretKey) &&
            str_starts_with($this->secretKey, 'sk_test_') &&
            !app()->runningUnitTests();
    }

    protected function maskPan(string $pan): string
    {
        if (strlen($pan) < 8) {
            return '•••• •••• •••• ' . substr($pan, -4);
        }

        $prefix = substr($pan, 0, 4);
        $suffix = substr($pan, -4);

        return "{$prefix} •••• •••• {$suffix}";
    }
}
