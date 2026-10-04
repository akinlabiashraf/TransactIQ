<?php

namespace App\Services\Gateway;

use App\Models\PaymentAttempt;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Services\Gateway\Adapters\SimulatedPaymentGateway;
use App\Services\Gateway\Contracts\PaymentGatewayInterface;
use App\Services\Gateway\DTOs\GatewayResponse;
use Illuminate\Support\Facades\Log;

class GatewayManager
{
    protected PaymentGatewayInterface $primaryGateway;
    protected PaymentGatewayInterface $fallbackGateway;

    public function __construct(
        ?PaymentGatewayInterface $primaryGateway = null,
        ?PaymentGatewayInterface $fallbackGateway = null
    ) {
        $this->primaryGateway = $primaryGateway ?? new SimulatedPaymentGateway('SIMULATED_PRIMARY');
        $this->fallbackGateway = $fallbackGateway ?? new SimulatedPaymentGateway('SIMULATED_FALLBACK');
    }

    /**
     * Execute payment charge across gateways with automatic fallback and attempt recording.
     */
    public function executeCharge(Transaction $transaction, array $paymentDetails): GatewayResponse
    {
        // 1. First Attempt: Primary Gateway
        $response1 = $this->primaryGateway->charge($transaction, $paymentDetails);
        $this->recordAttempt($transaction, 1, $response1);

        // If primary attempt succeeded or encountered a non-retryable terminal state (e.g. Card Expired, Insufficient Funds)
        if (!$response1->isRetryable) {
            return $response1;
        }

        // 2. Retryable failure detected (e.g. Bank Switch Down 91) -> Trigger Fallback Gateway
        TransactionEvent::create([
            'transaction_id' => $transaction->id,
            'from_status' => Transaction::STATUS_PROCESSING,
            'to_status' => Transaction::STATUS_PROCESSING,
            'event_type' => 'GATEWAY_FAILOVER_INITIATED',
            'triggered_by' => 'GATEWAY_MANAGER',
            'payload' => [
                'primary_provider' => $this->primaryGateway->getName(),
                'primary_error' => $response1->errorCode,
                'fallback_provider' => $this->fallbackGateway->getName(),
                'reason' => 'Primary payment gateway returned retryable network/switch failure. Attempting secondary route.',
            ],
        ]);

        $response2 = $this->fallbackGateway->charge($transaction, $paymentDetails);
        $this->recordAttempt($transaction, 2, $response2);

        return $response2;
    }

    /**
     * Persist an immutable payment attempt record in the database.
     */
    protected function recordAttempt(Transaction $transaction, int $attemptNumber, GatewayResponse $response): PaymentAttempt
    {
        return PaymentAttempt::create([
            'transaction_id' => $transaction->id,
            'attempt_number' => $attemptNumber,
            'provider' => $response->provider,
            'provider_reference' => $response->providerReference,
            'status' => $response->status,
            'error_code' => $response->errorCode,
            'error_message' => $response->errorMessage,
            'gateway_request' => $response->gatewayRequest,
            'gateway_response' => $response->gatewayResponse,
            'latency_ms' => $response->latencyMs,
        ]);
    }

    public function getPrimaryGateway(): PaymentGatewayInterface
    {
        return $this->primaryGateway;
    }

    public function getFallbackGateway(): PaymentGatewayInterface
    {
        return $this->fallbackGateway;
    }
}
