<?php

namespace App\Services\Gateway\Adapters;

use App\Models\Transaction;
use App\Services\Gateway\Contracts\PaymentGatewayInterface;
use App\Services\Gateway\DTOs\GatewayResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FlutterwavePaymentGateway implements PaymentGatewayInterface
{
    public const PROVIDER_NAME = 'FLUTTERWAVE';

    protected ?string $secretKey;
    protected string $baseUrl;
    protected int $timeoutSeconds;

    public function __construct(
        ?string $secretKey = null,
        ?string $baseUrl = null,
        int $timeoutSeconds = 8
    ) {
        $this->secretKey = $secretKey ?? config('services.flutterwave.secret_key', env('FLUTTERWAVE_SECRET_KEY'));
        $this->baseUrl = rtrim($baseUrl ?? config('services.flutterwave.base_url', 'https://api.flutterwave.com/v3'), '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    /**
     * Charge payment method using Flutterwave v3 API.
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

        if ($this->shouldExecuteLiveHttp()) {
            return $this->executeLiveCharge($transaction, $paymentDetails, $sanitizedRequest, $startTime);
        }

        return $this->simulateFlutterwaveSandbox($cardNumber, $sanitizedRequest, $paymentDetails, $startTime);
    }

    /**
     * Execute live charge against Flutterwave API.
     */
    protected function executeLiveCharge(
        Transaction $transaction,
        array $paymentDetails,
        array $sanitizedRequest,
        float $startTime
    ): GatewayResponse {
        try {
            $payload = [
                'tx_ref' => $transaction->reference,
                'amount' => (string) ($transaction->amount / 100), // FLW expects major units
                'currency' => $transaction->currency,
                'email' => $paymentDetails['customer']['email'] ?? 'customer@example.com',
                'payment_type' => 'card',
                'meta' => [
                    'transactiq_id' => $transaction->id,
                    'merchant_id' => $transaction->merchant_id,
                ],
            ];

            if (!empty($paymentDetails['card'])) {
                $payload['card_number'] = $paymentDetails['card']['number'] ?? '';
                $payload['cvv'] = $paymentDetails['card']['cvv'] ?? '';
                $payload['expiry_month'] = $paymentDetails['card']['exp_month'] ?? '';
                $payload['expiry_year'] = $paymentDetails['card']['exp_year'] ?? '';
            }

            $response = Http::withToken($this->secretKey)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post("{$this->baseUrl}/charges?type=card", $payload);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $body = $response->json() ?? [];
            $data = $body['data'] ?? [];

            if ($response->successful() && ($body['status'] ?? '') === 'success') {
                return GatewayResponse::success(
                    provider: self::PROVIDER_NAME,
                    providerReference: (string) ($data['id'] ?? ('FLW-' . Str::random(12))),
                    approvalCode: '00',
                    gatewayRequest: $sanitizedRequest,
                    gatewayResponse: $body,
                    latencyMs: $latencyMs
                );
            }

            // Authorization pending check
            if (($body['status'] ?? '') === 'success' && !empty($data['auth_url'])) {
                return GatewayResponse::pending(
                    provider: self::PROVIDER_NAME,
                    providerReference: (string) ($data['id'] ?? ('FLW-' . Str::random(12))),
                    approvalCode: '02',
                    message: 'Customer 3DS / OTP verification required by Flutterwave.',
                    gatewayRequest: $sanitizedRequest,
                    gatewayResponse: $body,
                    latencyMs: $latencyMs
                );
            }

            $isRetryable = $response->serverError() || in_array($response->status(), [408, 429, 502, 503, 504]);
            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'FLW_' . strtoupper(Str::slug($body['message'] ?? 'DECLINE', '_')),
                errorMessage: $body['message'] ?? 'Transaction failed via Flutterwave.',
                providerReference: isset($data['id']) ? (string) $data['id'] : null,
                approvalCode: '51',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: $body,
                latencyMs: $latencyMs,
                isRetryable: $isRetryable
            );
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            Log::warning('Flutterwave live gateway connection error: ' . $e->getMessage());

            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'FLW_TIMEOUT_OR_CONNECTION_ERROR',
                errorMessage: 'Connection to Flutterwave gateway timed out: ' . $e->getMessage(),
                gatewayRequest: $sanitizedRequest,
                latencyMs: $latencyMs,
                isRetryable: true
            );
        }
    }

    /**
     * Deterministic Flutterwave sandbox simulation.
     */
    protected function simulateFlutterwaveSandbox(
        string $cardNumber,
        array $sanitizedRequest,
        array $paymentDetails,
        float $startTime
    ): GatewayResponse {
        $latencyMs = rand(50, 105);
        $providerRef = 'FLW-' . strtoupper(Str::random(12));
        $gatewaySimFlag = strtoupper((string) ($paymentDetails['gateway_simulation'] ?? ''));

        // Scenario 1: OTP / PIN Required
        if (str_ends_with($cardNumber, '0002') || $gatewaySimFlag === 'PENDING') {
            return GatewayResponse::pending(
                provider: self::PROVIDER_NAME,
                providerReference: $providerRef,
                approvalCode: '02',
                message: 'Flutterwave PIN/OTP authentication required.',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'status' => 'success',
                    'message' => 'Charge Authorization Required',
                    'data' => [
                        'id' => $providerRef,
                        'status' => 'pending',
                        'auth_model' => 'AUTH_PIN_OTP',
                        'auth_url' => 'https://sandbox.flutterwave.com/pay/' . $providerRef,
                    ],
                ],
                latencyMs: $latencyMs
            );
        }

        // Scenario 2: Switch Timeout / Retryable failover
        if (str_ends_with($cardNumber, '0091')) {
            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'FLW_NETWORK_SWITCH_TIMEOUT',
                errorMessage: 'Flutterwave switch timeout: Card processor unreachable.',
                providerReference: $providerRef,
                approvalCode: '91',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'status' => 'error',
                    'message' => 'Network switch timeout',
                    'data' => ['id' => $providerRef, 'processor_response' => 'Switch Unavailable'],
                ],
                latencyMs: $latencyMs + 40,
                isRetryable: true // Auto failover to next gateway
            );
        }

        // Scenario 3: Decline / Insufficient funds
        if (str_ends_with($cardNumber, '0051') || $gatewaySimFlag === 'FAILED') {
            return GatewayResponse::failure(
                provider: self::PROVIDER_NAME,
                errorCode: 'FLW_DECLINED_INSUFFICIENT_FUNDS',
                errorMessage: 'Flutterwave decline: Insufficient balance.',
                providerReference: $providerRef,
                approvalCode: '51',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'status' => 'error',
                    'message' => 'Insufficient funds',
                    'data' => ['id' => $providerRef, 'processor_response' => 'Declined 51'],
                ],
                latencyMs: $latencyMs,
                isRetryable: false
            );
        }

        // Scenario 4: Approved
        return GatewayResponse::success(
            provider: self::PROVIDER_NAME,
            providerReference: $providerRef,
            approvalCode: '00',
            gatewayRequest: $sanitizedRequest,
            gatewayResponse: [
                'status' => 'success',
                'message' => 'Charge successful',
                'data' => [
                    'id' => $providerRef,
                    'tx_ref' => $sanitizedRequest['reference'],
                    'status' => 'successful',
                    'amount' => $sanitizedRequest['amount'] / 100,
                    'currency' => $sanitizedRequest['currency'],
                    'payment_type' => 'card',
                ],
            ],
            latencyMs: $latencyMs
        );
    }

    protected function shouldExecuteLiveHttp(): bool
    {
        return !empty($this->secretKey) &&
            str_starts_with($this->secretKey, 'FLWSECK_') &&
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
