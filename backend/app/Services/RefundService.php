<?php

namespace App\Services;

use App\Models\Refund;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Services\Ledger\LedgerService;
use App\Services\Security\AuditLogService;
use App\Services\Webhook\WebhookDispatcherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class RefundService
{
    public function __construct(
        protected ?LedgerService $ledgerService = null,
        protected ?AuditLogService $auditLogService = null,
        protected ?WebhookDispatcherService $webhookDispatcher = null
    ) {
        $this->ledgerService = $ledgerService ?? app(LedgerService::class);
        $this->auditLogService = $auditLogService ?? app(AuditLogService::class);
        $this->webhookDispatcher = $webhookDispatcher ?? app(WebhookDispatcherService::class);
    }

    /**
     * Process a full or partial refund on an eligible payment transaction.
     *
     * @throws UnprocessableEntityHttpException if transaction is not in a refundable state or amount exceeds available balance.
     */
    public function processRefund(
        Transaction $transaction,
        ?int $amount = null,
        string $reason = Refund::REASON_CUSTOMER_REQUEST,
        ?array $metadata = null
    ): Refund {
        // 1. Validate Transaction State
        if (!in_array($transaction->status, [Transaction::STATUS_SUCCESS, Transaction::STATUS_PARTIALLY_REFUNDED], true)) {
            throw new UnprocessableEntityHttpException(
                "Transaction [{$transaction->reference}] with status [{$transaction->status}] is not eligible for refund. Only successful or partially refunded transactions can be refunded."
            );
        }

        $maxRefundable = $transaction->refundableAmount();
        $refundAmount = $amount ?? $maxRefundable;

        if ($refundAmount <= 0) {
            throw new UnprocessableEntityHttpException(
                "Refund amount must be greater than zero. Provided: {$refundAmount}"
            );
        }

        if ($refundAmount > $maxRefundable) {
            throw new UnprocessableEntityHttpException(
                "Requested refund amount [₦" . number_format($refundAmount / 100, 2) . "] exceeds the remaining refundable balance [₦" . number_format($maxRefundable / 100, 2) . "] for transaction [{$transaction->reference}]."
            );
        }

        return DB::transaction(function () use ($transaction, $refundAmount, $reason, $metadata) {
            $date = now()->format('Ymd');
            $reference = "REF-{$date}-" . strtoupper(Str::random(8));

            // 2. Create Refund Record
            $refund = Refund::create([
                'reference' => $reference,
                'transaction_id' => $transaction->id,
                'merchant_id' => $transaction->merchant_id,
                'amount' => $refundAmount,
                'currency' => $transaction->currency,
                'status' => Refund::STATUS_COMPLETED,
                'reason' => $reason,
                'gateway_refund_reference' => 'GATEWAY-REF-' . strtoupper(Str::random(10)),
                'metadata' => $metadata,
            ]);

            // 3. Determine New Transaction Status
            $newTotalRefunded = $transaction->totalRefundedAmount();
            $isFullyRefunded = ($newTotalRefunded >= $transaction->amount);
            $nextStatus = $isFullyRefunded ? Transaction::STATUS_REFUNDED : Transaction::STATUS_PARTIALLY_REFUNDED;

            $prevStatus = $transaction->status;
            $transaction->update(['status' => $nextStatus]);

            // 4. Log FSM Event
            TransactionEvent::create([
                'transaction_id' => $transaction->id,
                'from_status' => $prevStatus,
                'to_status' => $nextStatus,
                'event_type' => $isFullyRefunded ? 'TRANSACTION_FULLY_REFUNDED' : 'TRANSACTION_PARTIALLY_REFUNDED',
                'triggered_by' => 'REFUND_ENGINE',
                'payload' => [
                    'refund_reference' => $refund->reference,
                    'refund_amount' => $refund->amount,
                    'total_refunded' => $newTotalRefunded,
                    'remaining_balance' => max(0, $transaction->amount - $newTotalRefunded),
                    'reason' => $reason,
                ],
            ]);

            // 5. Post Balanced Double-Entry General Ledger Reversal
            $this->ledgerService->recordRefund($refund);

            // 6. Record Tamper-Evident SHA-256 Chained Audit Log
            $this->auditLogService->logPaymentEvent($transaction, 'PAYMENT_REFUNDED', [
                'refund_id' => $refund->id,
                'refund_reference' => $refund->reference,
                'refund_amount' => $refund->amount,
                'reason' => $reason,
                'new_status' => $nextStatus,
            ]);

            // 7. Dispatch Webhook Event (payment.refunded)
            $this->webhookDispatcher->dispatchTransactionWebhook($transaction, 'payment.refunded', [
                'refund' => [
                    'id' => $refund->id,
                    'reference' => $refund->reference,
                    'amount' => $refund->amount,
                    'currency' => $refund->currency,
                    'reason' => $refund->reason,
                    'status' => $refund->status,
                    'created_at' => $refund->created_at->toIso8601String(),
                ],
            ]);

            return $refund->load('transaction');
        });
    }
}
