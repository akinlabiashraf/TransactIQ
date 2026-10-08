<?php

namespace App\Services\Gateway\Adapters;

use App\Models\Transaction;
use App\Services\Gateway\Contracts\PaymentGatewayInterface;
use App\Services\Gateway\DTOs\GatewayResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaystackPaymentGateway implements PaymentGatewayInterface
{
    public const PROVIDER_NAME = 'PAYSTACK';

    protected ?string $secretKey;
    protected string $baseUrl;
    protected int $timeoutSeconds;

    public function __construct(
        ?string $secretKey = null,
        ?string $baseUrl = null,
        int $timeoutSeconds = 8
    ) {
        $this->secretKey = $secretKey ?? config('services.paystack.secret_key', env('PAYSTACK_SECRET_KEY'));
        $this->baseUrl = rtrim($baseUrl ?? config('services.paystack.base_url', 'https://api.paystack.co'), '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    /**
     * Charge a customer payment method using the Paystack Gateway API.
     */
    public function charge(Transaction $transaction, array $paymentDetails): GatewayResponse
    {
        $startTime = microtime(true);
        $card = $paymentDetails['card'] ?? [];
        $cardNumber = preg_replace('/\D/', '', (string) ($card['number'] ?? ''));
        $maskedPan = $this->maskPan($cardNumber);

        $sanitizedRequest = [
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'reference' => $transaction->reference,
            'email' => $paymentDetails['customer']['email'] ?? 'customer@example.com',
            'masked_card' => $maskedPan,
            'gateway_provider' => self::PROVIDER_NAME,
        ];

        // If a real secret key is configured and not in unit testing mock mode, execute real HTTP call
        if ($this->shouldExecuteLiveHttp()) {
            return $this->executeLiveCharge($transaction, $paymentDetails, $sanitizedRequest, $startTime);
        }

        // Deterministic Paystack Sandbox Simulation Protocol
        return $this->simulatePaystackSandbox($cardNumber, $sanitizedRequest, $paymentDetails, $startTime);
    }

    /**
     * Execute live charge against Paystack REST API.
     */
    protected function executeLiveCharge(
        Transaction $transaction,
        array $paymentDetails,
        array $sanitizedRequest,
        float $startTime
    ): GatewayResponse {
        try {
            $payload = [
                'email' => $paymentDetails['customer']['email'] ?? 'customer@example.com',
                'amount' => $transaction->amount, // in minor units (kobo/cents)
                'reference' => $transaction->reference,
                'currency' => $transaction->currency,
                'metadata' => [
                    'transactiq_id' => $transaction->id,
                    'merchant_id' => $transaction->merchant_id,
                ],
            ];

            if (!empty($paymentDetails['card'])) {
                $payload['card'] = [
                    'number' => $paymentDetails['card']['number'] ?? '',
                    'cvv' => $paymentDetails['card']['cvv'] ?? '',
                    'expiry_month' => $paymentDetails['card']['exp_month'] ?? '',
                    'expiry_year' => $paymentDetails['card']['exp_year'] ?? '',
                ];
            }

            $response = Http::withToken($this->secretKey)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post("{$this->baseUrl}/charge", $payload);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $body = $response->json() ?? [];
            $data = $body['data'] ?? [];

            if ($response->successful() && ($data['status'] ?? '') === 'success') {
                return GatewayResponse::success(
                    provider: self::PROVIDER_NAME,
                    providerReference: $data['reference'] ?? ('PSTK-' . Str::random(12)),
                    approvalCode: '00',
                    gatewayRequest: $sanitizedRequest,
                    gatewayResponse: $body,
                    latencyMs: $latencyMs
                );
            }

            // Paystack OTP / 3DS requirement check
            if (($data['status'] ?? '') === 'send_otp' || ($data['status'] ?? '') === 'open_url') {
                return GatewayResponse::pending(
                    provider: self::PROVIDER_NAME,
                    providerReference: $data['reference'] ?? ('PSTK-' . Str::random(12)),
                    approvalCode: '02',
                    message: $data['message'] ?? 'OTP or 3D Secure verification required.',
                    gatewayRequest: $sanitizedRequest,
                    gatewayResponse: $body,
                    latencyMs: $latencyMs
                );
            }

            // Failure handling
            $isRetryable = $response->serverError() || in_array($response->status(), [408, 429, 502, 503, 504]);
            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'PSTK_' . strtoupper($data['gateway_response'] ?? 'CHARGE_FAILED'),
                errorMessage: $data['message'] ?? ($body['message'] ?? 'Charge attempt rejected by Paystack.'),
                providerReference: $data['reference'] ?? null,
                approvalCode: '51',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: $body,
                latencyMs: $latencyMs,
                isRetryable: $isRetryable
            );
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            Log::warning('Paystack live gateway connection error: ' . $e->getMessage());

            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'PSTK_TIMEOUT_OR_CONNECTION_ERROR',
                errorMessage: 'Connection to Paystack gateway timed out: ' . $e->getMessage(),
                gatewayRequest: $sanitizedRequest,
                latencyMs: $latencyMs,
                isRetryable: true // Auto failover on connectivity timeout
            );
        }
    }

    /**
     * Deterministic sandbox protocol matching Paystack test card specs.
     */
    protected function simulatePaystackSandbox(
        string $cardNumber,
        array $sanitizedRequest,
        array $paymentDetails,
        float $startTime
    ): GatewayResponse {
        $latencyMs = rand(45, 95);
        $providerRef = 'PSTK-' . strtoupper(Str::random(12));
        $gatewaySimFlag = strtoupper((string) ($paymentDetails['gateway_simulation'] ?? ''));

        // Paystack Test Card 1: 3D Secure OTP pending (...4082 or flag PENDING)
        if (str_ends_with($cardNumber, '4082') || str_ends_with($cardNumber, '0002') || $gatewaySimFlag === 'PENDING') {
            return GatewayResponse::pending(
                provider: self::PROVIDER_NAME,
                providerReference: $providerRef,
                approvalCode: '02',
                message: 'Paystack OTP challenge required.',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'status' => true,
                    'message' => 'Charge attempted',
                    'data' => [
                        'reference' => $providerRef,
                        'status' => 'send_otp',
                        'display_text' => 'Please enter the OTP sent to your phone',
                        'gateway_response' => 'Please enter OTP',
                    ],
                ],
                latencyMs: $latencyMs
            );
        }

        // Paystack Test Card 2: Insufficient Funds (...4083 or ...0051)
        if (str_ends_with($cardNumber, '4083') || str_ends_with($cardNumber, '0051')) {
            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'PSTK_INSUFFICIENT_FUNDS',
                errorMessage: 'Paystack decline: Insufficient Funds in customer account.',
                providerReference: $providerRef,
                approvalCode: '51',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'status' => false,
                    'message' => 'Insufficient funds',
                    'data' => [
                        'reference' => $providerRef,
                        'status' => 'failed',
                        'gateway_response' => 'Insufficient Funds',
                    ],
                ],
                latencyMs: $latencyMs,
                isRetryable: false
            );
        }

        // Paystack Test Card 3: Switch Timeout (Retryable Failover ...4085 or ...0091)
        if (str_ends_with($cardNumber, '4085') || str_ends_with($cardNumber, '0091')) {
            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'PSTK_SWITCH_UNAVAILABLE',
                errorMessage: 'Paystack switch timeout: Bank switch network unreachable (Code 91).',
                providerReference: $providerRef,
                approvalCode: '91',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'status' => false,
                    'message' => 'Issuer switch timeout',
                    'data' => [
                        'reference' => $providerRef,
                        'status' => 'failed',
                        'gateway_response' => 'Switch Unavailable',
                    ],
                ],
                latencyMs: $latencyMs + 50,
                isRetryable: true // Triggers automatic failover to fallback gateway!
            );
        }

        // Paystack Test Card 4: General Card Declined (...4084 or flag FAILED)
        if (str_ends_with($cardNumber, '4084') || $gatewaySimFlag === 'FAILED') {
            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'PSTK_CARD_DECLINED',
                errorMessage: 'Paystack decline: Transaction declined by bank issuer.',
                providerReference: $providerRef,
                approvalCode: '05',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'status' => false,
                    'message' => 'Card declined',
                    'data' => [
                        'reference' => $providerRef,
                        'status' => 'failed',
                        'gateway_response' => 'Declined',
                    ],
                ],
                latencyMs: $latencyMs,
                isRetryable: false
            );
        }

        // Paystack Standard Success (...4081 or default card)
        return GatewayResponse::success(
            provider: self::PROVIDER_NAME,
            providerReference: $providerRef,
            approvalCode: '00',
            gatewayRequest: $sanitizedRequest,
            gatewayResponse: [
                'status' => true,
                'message' => 'Charge attempted',
                'data' => [
                    'reference' => $providerRef,
                    'status' => 'success',
                    'gateway_response' => 'Approved',
                    'channel' => 'card',
                    'currency' => $sanitizedRequest['currency'],
                    'amount' => $sanitizedRequest['amount'],
                ],
            ],
            latencyMs: $latencyMs
        );
    }

    protected function shouldExecuteLiveHttp(): bool
    {
        return !empty($this->secretKey) &&
            str_starts_with($this->secretKey, 'sk_') &&
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
