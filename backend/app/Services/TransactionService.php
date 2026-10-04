<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Services\Gateway\GatewayManager;
use App\Services\Ledger\LedgerService;
use App\Services\Security\AuditLogService;
use App\Services\Security\RiskService;
use App\Services\Webhook\WebhookDispatcherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionService
{
    public function __construct(
        protected ?GatewayManager $gatewayManager = null,
        protected ?WebhookDispatcherService $webhookDispatcher = null,
        protected ?LedgerService $ledgerService = null,
        protected ?RiskService $riskService = null,
        protected ?AuditLogService $auditLogService = null,
        protected ?IdempotencyService $idempotencyService = null
    ) {
        $this->gatewayManager = $gatewayManager ?? new GatewayManager();
        $this->webhookDispatcher = $webhookDispatcher ?? app(WebhookDispatcherService::class);
        $this->ledgerService = $ledgerService ?? app(LedgerService::class);
        $this->riskService = $riskService ?? app(RiskService::class);
        $this->auditLogService = $auditLogService ?? app(AuditLogService::class);
        $this->idempotencyService = $idempotencyService ?? app(IdempotencyService::class);
    }
    /**
     * Resolve an existing customer or register a new one under this merchant.
     */
    public function resolveCustomer(Merchant $merchant, array $data): Customer
    {
        return Customer::firstOrCreate(
            [
                'merchant_id' => $merchant->id,
                'email' => strtolower(trim($data['email'])),
            ],
            [
                'customer_code' => 'CUST-' . strtoupper(Str::random(8)),
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'metadata' => [
                    'source' => 'api_checkout',
                    'created_via_payment' => true,
                ],
            ]
        );
    }

    /**
     * Calculate platform fee and net merchant payout amount.
     *
     * @return array{fee_amount: int, net_amount: int}
     */
    public function calculateFees(Merchant $merchant, int $amountMinor): array
    {
        $basisPoints = $merchant->fee_basis_points ?? 150; // default 1.5%
        $flatFee = $merchant->fee_flat_minor ?? 0;

        $percentageFee = (int) round($amountMinor * ($basisPoints / 10000));
        $totalFee = $percentageFee + $flatFee;
        $netAmount = max(0, $amountMinor - $totalFee);

        return [
            'fee_amount' => $totalFee,
            'net_amount' => $netAmount,
        ];
    }

    /**
     * Generate a unique, traceable transaction reference.
     * Example: TXN-20260921-A9F3E8
     */
    public function generateReference(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(8));
        return "TXN-{$date}-{$random}";
    }

    /**
     * Create and execute the payment through the deterministic state machine.
     */
    public function createAndProcessPayment(Merchant $merchant, array $payload, string $idempotencyKey): Transaction
    {
        return DB::transaction(function () use ($merchant, $payload, $idempotencyKey) {
            $customer = $this->resolveCustomer($merchant, $payload['customer']);
            $fees = $this->calculateFees($merchant, (int) $payload['amount']);
            $reference = $this->generateReference();
            $requestHash = $this->idempotencyService->computePayloadHash($payload);

            // 1. Create Transaction in INITIATED state
            $transaction = Transaction::create([
                'reference' => $reference,
                'merchant_id' => $merchant->id,
                'customer_id' => $customer->id,
                'amount' => (int) $payload['amount'],
                'fee_amount' => $fees['fee_amount'],
                'net_amount' => $fees['net_amount'],
                'currency' => strtoupper($payload['currency']),
                'status' => Transaction::STATUS_INITIATED,
                'payment_method' => $payload['payment_method'],
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'provider' => 'SIMULATED_GATEWAY',
                'metadata' => $payload['metadata'] ?? null,
            ]);

            // 2. Log INITIATED event
            TransactionEvent::create([
                'transaction_id' => $transaction->id,
                'from_status' => null,
                'to_status' => Transaction::STATUS_INITIATED,
                'event_type' => 'TRANSACTION_INITIATED',
                'triggered_by' => 'MERCHANT_API',
                'payload' => [
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'customer_id' => $customer->id,
                    'idempotency_key' => $idempotencyKey,
                ],
            ]);

            // 2b. Evaluate Real-Time Risk & Velocity Heuristics
            $riskResult = $this->riskService->evaluate($merchant, $customer, $payload);

            if ($riskResult->isBlocked()) {
                $transaction->update([
                    'status' => Transaction::STATUS_FAILED,
                    'failure_reason' => 'Risk Engine Block: ' . $riskResult->getReason(),
                ]);

                TransactionEvent::create([
                    'transaction_id' => $transaction->id,
                    'from_status' => Transaction::STATUS_INITIATED,
                    'to_status' => Transaction::STATUS_FAILED,
                    'event_type' => 'PAYMENT_BLOCKED_BY_RISK',
                    'triggered_by' => 'RISK_ENGINE',
                    'payload' => [
                        'score' => $riskResult->score,
                        'flags' => $riskResult->flags,
                        'reason' => $riskResult->reason,
                        'metadata' => $riskResult->metadata,
                    ],
                ]);

                // Record Tamper-Evident Audit Log
                $this->auditLogService->logPaymentEvent($transaction, 'PAYMENT_RISK_BLOCKED', [
                    'risk_score' => $riskResult->score,
                    'risk_flags' => $riskResult->flags,
                    'reason' => $riskResult->reason,
                ]);

                // Dispatch payment.failed webhook
                $this->webhookDispatcher->dispatchTransactionWebhook($transaction, 'payment.failed');

                return $transaction->load(['customer', 'events', 'paymentAttempts']);
            }

            if ($riskResult->isReview()) {
                TransactionEvent::create([
                    'transaction_id' => $transaction->id,
                    'from_status' => Transaction::STATUS_INITIATED,
                    'to_status' => Transaction::STATUS_INITIATED,
                    'event_type' => 'RISK_REVIEW_FLAGGED',
                    'triggered_by' => 'RISK_ENGINE',
                    'payload' => [
                        'score' => $riskResult->score,
                        'flags' => $riskResult->flags,
                        'reason' => $riskResult->reason,
                    ],
                ]);
            }

            // 3. Transition to PROCESSING
            $transaction->update(['status' => Transaction::STATUS_PROCESSING]);

            TransactionEvent::create([
                'transaction_id' => $transaction->id,
                'from_status' => Transaction::STATUS_INITIATED,
                'to_status' => Transaction::STATUS_PROCESSING,
                'event_type' => 'STATE_TRANSITION',
                'triggered_by' => 'TRANSACTION_ENGINE',
                'payload' => ['dispatched_to_provider' => 'SIMULATED_GATEWAY'],
            ]);

            // 4. Dispatch Payment to Gateway Manager (with deterministic simulation & fallback retry)
            $gatewayResponse = $this->gatewayManager->executeCharge($transaction, $payload);

            // 5. Apply Final State Transition based on Gateway Response
            if ($gatewayResponse->isSuccess()) {
                $transaction->update([
                    'status' => Transaction::STATUS_SUCCESS,
                    'provider' => $gatewayResponse->provider,
                    'provider_reference' => $gatewayResponse->providerReference,
                    'paid_at' => now(),
                ]);

                TransactionEvent::create([
                    'transaction_id' => $transaction->id,
                    'from_status' => Transaction::STATUS_PROCESSING,
                    'to_status' => Transaction::STATUS_SUCCESS,
                    'event_type' => 'PAYMENT_APPROVED',
                    'triggered_by' => 'GATEWAY_ADAPTER',
                    'payload' => [
                        'provider' => $gatewayResponse->provider,
                        'provider_reference' => $gatewayResponse->providerReference,
                        'approval_code' => $gatewayResponse->approvalCode,
                        'latency_ms' => $gatewayResponse->latencyMs,
                        'paid_at' => now()->toIso8601String(),
                    ],
                ]);

                // Record balanced double-entry financial ledger journal entries
                $this->ledgerService->recordPaymentCapture($transaction);

                // Record Tamper-Evident Audit Log
                $this->auditLogService->logPaymentEvent($transaction, 'PAYMENT_CAPTURED');

                // Dispatch payment.success webhook asynchronously
                $this->webhookDispatcher->dispatchTransactionWebhook($transaction, 'payment.success');
            } elseif ($gatewayResponse->isPending()) {
                $transaction->update([
                    'status' => Transaction::STATUS_PENDING,
                    'provider' => $gatewayResponse->provider,
                    'provider_reference' => $gatewayResponse->providerReference,
                ]);

                TransactionEvent::create([
                    'transaction_id' => $transaction->id,
                    'from_status' => Transaction::STATUS_PROCESSING,
                    'to_status' => Transaction::STATUS_PENDING,
                    'event_type' => 'PAYMENT_PENDING_EXTERNAL',
                    'triggered_by' => 'GATEWAY_ADAPTER',
                    'payload' => [
                        'provider' => $gatewayResponse->provider,
                        'provider_reference' => $gatewayResponse->providerReference,
                        'approval_code' => $gatewayResponse->approvalCode,
                        'message' => $gatewayResponse->errorMessage,
                    ],
                ]);

                // Record Tamper-Evident Audit Log
                $this->auditLogService->logPaymentEvent($transaction, 'PAYMENT_PENDING');
            } else {
                $transaction->update([
                    'status' => Transaction::STATUS_FAILED,
                    'provider' => $gatewayResponse->provider,
                    'provider_reference' => $gatewayResponse->providerReference,
                    'failure_reason' => $gatewayResponse->errorMessage ?? 'Card declined by issuing bank',
                ]);

                TransactionEvent::create([
                    'transaction_id' => $transaction->id,
                    'from_status' => Transaction::STATUS_PROCESSING,
                    'to_status' => Transaction::STATUS_FAILED,
                    'event_type' => 'PAYMENT_DECLINED',
                    'triggered_by' => 'GATEWAY_ADAPTER',
                    'payload' => [
                        'provider' => $gatewayResponse->provider,
                        'error_code' => $gatewayResponse->errorCode,
                        'error_message' => $gatewayResponse->errorMessage,
                    ],
                ]);

                // Record Tamper-Evident Audit Log
                $this->auditLogService->logPaymentEvent($transaction, 'PAYMENT_DECLINED', [
                    'failure_reason' => $transaction->failure_reason,
                ]);

                // Dispatch payment.failed webhook asynchronously
                $this->webhookDispatcher->dispatchTransactionWebhook($transaction, 'payment.failed');
            }

            return $transaction->load(['customer', 'events', 'paymentAttempts']);
        });
    }
}
