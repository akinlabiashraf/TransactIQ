<?php

namespace App\Services\Gateway\Adapters;

use App\Models\Transaction;
use App\Services\Gateway\Contracts\PaymentGatewayInterface;
use App\Services\Gateway\DTOs\GatewayResponse;
use Illuminate\Support\Str;

class SimulatedPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        protected string $providerName = 'SIMULATED_PRIMARY'
    ) {}

    public function getName(): string
    {
        return $this->providerName;
    }

    /**
     * Charge transaction against simulated bank network using deterministic card rules.
     */
    public function charge(Transaction $transaction, array $paymentDetails): GatewayResponse
    {
        $card = $paymentDetails['card'] ?? [];
        $cardNumber = preg_replace('/\D/', '', (string) ($card['number'] ?? ''));
        $gatewaySimFlag = strtoupper((string) ($paymentDetails['gateway_simulation'] ?? ''));

        $providerRef = 'PROV-' . strtoupper(Str::random(12));
        $maskedPan = $this->maskPan($cardNumber);

        $sanitizedRequest = [
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'reference' => $transaction->reference,
            'masked_card' => $maskedPan,
            'channel' => $paymentDetails['payment_method'] ?? 'CARD',
            'gateway_provider' => $this->providerName,
        ];

        // Scenario 1: Bank Issuer Down / Switch Timeout (Card ending in 0091)
        if (str_ends_with($cardNumber, '0091')) {
            if ($this->providerName === 'SIMULATED_PRIMARY') {
                return GatewayResponse::failure(
                    provider: $this->providerName,
                    errorCode: 'BANK_ISSUER_DOWN_91',
                    errorMessage: 'Issuing bank network switch is unresponsive or unavailable (Code 91).',
                    providerReference: $providerRef,
                    approvalCode: '91',
                    gatewayRequest: $sanitizedRequest,
                    gatewayResponse: [
                        'provider_reference' => $providerRef,
                        'status' => 'FAILED',
                        'response_code' => '91',
                        'error' => 'SWITCH_UNAVAILABLE',
                        'message' => 'Issuing bank network switch is unresponsive or unavailable (Code 91).',
                    ],
                    latencyMs: rand(180, 260),
                    isRetryable: true // Triggers automatic failover to fallback gateway
                );
            }

            // If routed to FALLBACK gateway, the alternative route succeeds!
            return GatewayResponse::success(
                provider: $this->providerName,
                providerReference: $providerRef,
                approvalCode: '00',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'provider_reference' => $providerRef,
                    'status' => 'SUCCESS',
                    'approval_code' => '00',
                    'route' => 'SECONDARY_FALLBACK_SWITCH',
                    'message' => 'Approved via secondary interbank switch route.',
                ],
                latencyMs: rand(85, 140)
            );
        }

        // Scenario 2: Insufficient Funds (Card ending in 0051 or simulation flag FAILED)
        if (str_ends_with($cardNumber, '0051') || $gatewaySimFlag === 'FAILED') {
            $errorMsg = ($gatewaySimFlag === 'FAILED' && empty($cardNumber))
                ? 'Card declined by issuing bank'
                : 'Insufficient funds in customer account (Decline 51).';

            return GatewayResponse::failure(
                provider: $this->providerName,
                errorCode: 'DECLINED_51',
                errorMessage: $errorMsg,
                providerReference: $providerRef,
                approvalCode: '51',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'provider_reference' => $providerRef,
                    'status' => 'FAILED',
                    'response_code' => '51',
                    'error' => 'INSUFFICIENT_FUNDS',
                    'message' => $errorMsg,
                ],
                latencyMs: rand(65, 110),
                isRetryable: false
            );
        }

        // Scenario 3: Expired Card (Card ending in 0033)
        if (str_ends_with($cardNumber, '0033')) {
            return GatewayResponse::failure(
                provider: $this->providerName,
                errorCode: 'CARD_EXPIRED_33',
                errorMessage: 'Card authorization failed: Card has expired (Decline 33).',
                providerReference: $providerRef,
                approvalCode: '33',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'provider_reference' => $providerRef,
                    'status' => 'FAILED',
                    'response_code' => '33',
                    'error' => 'CARD_EXPIRED',
                    'message' => 'Card has expired.',
                ],
                latencyMs: rand(45, 80),
                isRetryable: false
            );
        }

        // Scenario 4: 3D Secure / OTP Required (Card ending in 0002 or simulation flag PENDING)
        if (str_ends_with($cardNumber, '0002') || $gatewaySimFlag === 'PENDING') {
            return GatewayResponse::pending(
                provider: $this->providerName,
                providerReference: $providerRef,
                approvalCode: '02',
                message: '3D Secure OTP verification required by card issuer.',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'provider_reference' => $providerRef,
                    'status' => 'PENDING',
                    'response_code' => '02',
                    'auth_url' => 'https://sandbox.transactiq.io/auth/3ds/' . $providerRef,
                    'message' => 'Customer must complete 3D Secure challenge.',
                ],
                latencyMs: rand(75, 120)
            );
        }

        // Scenario 5: Gateway Timeout Simulation (Card ending in 0504)
        if (str_ends_with($cardNumber, '0504')) {
            return new GatewayResponse(
                status: 'PENDING',
                provider: $this->providerName,
                providerReference: $providerRef,
                approvalCode: '504',
                errorCode: 'GATEWAY_TIMEOUT_504',
                errorMessage: 'Gateway connection timed out awaiting issuing bank authorization. State placed in PENDING for reconciliation.',
                gatewayRequest: $sanitizedRequest,
                gatewayResponse: [
                    'provider_reference' => $providerRef,
                    'status' => 'TIMEOUT',
                    'response_code' => '504',
                    'error' => 'GATEWAY_TIMEOUT',
                ],
                latencyMs: rand(280, 450),
                isRetryable: false
            );
        }

        // Scenario 6: Default / Standard Card ending in 0001 (Instant Success)
        return GatewayResponse::success(
            provider: $this->providerName,
            providerReference: $providerRef,
            approvalCode: '00',
            gatewayRequest: $sanitizedRequest,
            gatewayResponse: [
                'provider_reference' => $providerRef,
                'status' => 'SUCCESS',
                'approval_code' => '00',
                'authorization_code' => 'AUTH_' . strtoupper(Str::random(8)),
                'message' => 'Transaction successfully approved by issuer.',
            ],
            latencyMs: rand(50, 95)
        );
    }

    /**
     * Mask card primary account number (PAN) to comply with PCI-DSS log standards.
     * Example: 4000 0000 0000 0001 -> 4000 •••• •••• 0001
     */
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
