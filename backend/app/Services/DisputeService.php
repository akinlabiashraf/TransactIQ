<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Services\Ledger\LedgerService;
use App\Services\Security\AuditLogService;
use App\Services\Webhook\WebhookDispatcherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class DisputeService
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
     * Open a formal dispute / chargeback against a transaction and place funds in escrow reserve.
     */
    public function createDispute(
        Transaction $transaction,
        ?int $amount = null,
        string $reason = Dispute::REASON_CHARGEBACK_FRAUD,
        ?array $metadata = null
    ): Dispute {
        if (!in_array($transaction->status, [Transaction::STATUS_SUCCESS, Transaction::STATUS_PARTIALLY_REFUNDED], true)) {
            throw new UnprocessableEntityHttpException(
                "Transaction [{$transaction->reference}] with status [{$transaction->status}] cannot be disputed. Only successful transactions can be disputed."
            );
        }

        $disputeAmount = $amount ?? $transaction->amount;

        return DB::transaction(function () use ($transaction, $disputeAmount, $reason, $metadata) {
            $date = now()->format('Ymd');
            $reference = "DSP-{$date}-" . strtoupper(Str::random(8));

            $dispute = Dispute::create([
                'reference' => $reference,
                'transaction_id' => $transaction->id,
                'merchant_id' => $transaction->merchant_id,
                'amount' => $disputeAmount,
                'currency' => $transaction->currency,
                'status' => Dispute::STATUS_OPEN,
                'reason' => $reason,
                'due_at' => now()->addDays(7),
                'metadata' => $metadata,
            ]);

            $prevStatus = $transaction->status;
            $transaction->update(['status' => Transaction::STATUS_DISPUTED]);

            TransactionEvent::create([
                'transaction_id' => $transaction->id,
                'from_status' => $prevStatus,
                'to_status' => Transaction::STATUS_DISPUTED,
                'event_type' => 'TRANSACTION_DISPUTED',
                'triggered_by' => 'DISPUTE_ENGINE',
                'payload' => [
                    'dispute_reference' => $dispute->reference,
                    'dispute_amount' => $dispute->amount,
                    'reason' => $reason,
                    'due_at' => $dispute->due_at?->toIso8601String(),
                ],
            ]);

            // Place funds into Dispute Escrow Reserve
            $this->ledgerService->recordDisputeHold($dispute);

            // Audit Log
            $this->auditLogService->logPaymentEvent($transaction, 'PAYMENT_DISPUTED', [
                'dispute_id' => $dispute->id,
                'dispute_reference' => $dispute->reference,
                'amount' => $disputeAmount,
                'reason' => $reason,
            ]);

            // Webhook
            $this->webhookDispatcher->dispatchTransactionWebhook($transaction, 'payment.disputed', [
                'dispute' => [
                    'id' => $dispute->id,
                    'reference' => $dispute->reference,
                    'amount' => $dispute->amount,
                    'currency' => $dispute->currency,
                    'reason' => $dispute->reason,
                    'status' => $dispute->status,
                    'due_at' => $dispute->due_at?->toIso8601String(),
                ],
            ]);

            return $dispute->load('transaction');
        });
    }

    /**
     * Submit merchant counter-evidence for dispute defense.
     */
    public function submitEvidence(Dispute $dispute, array $evidence): Dispute
    {
        if ($dispute->status !== Dispute::STATUS_OPEN && $dispute->status !== Dispute::STATUS_UNDER_REVIEW) {
            throw new UnprocessableEntityHttpException(
                "Cannot submit evidence for dispute [{$dispute->reference}] because it is in status [{$dispute->status}]."
            );
        }

        $dispute->update([
            'status' => Dispute::STATUS_UNDER_REVIEW,
            'evidence' => array_merge($dispute->evidence ?? [], $evidence),
        ]);

        $this->auditLogService->logPaymentEvent($dispute->transaction, 'DISPUTE_EVIDENCE_SUBMITTED', [
            'dispute_reference' => $dispute->reference,
            'evidence_keys' => array_keys($evidence),
        ]);

        return $dispute;
    }

    /**
     * Resolve a dispute (adjudication outcome: WON or LOST).
     */
    public function resolveDispute(Dispute $dispute, string $outcome, ?string $resolutionNote = null): Dispute
    {
        $outcome = strtoupper(trim($outcome));
        if (!in_array($outcome, [Dispute::STATUS_WON, Dispute::STATUS_LOST], true)) {
            throw new UnprocessableEntityHttpException(
                "Invalid dispute resolution outcome [{$outcome}]. Expected 'WON' or 'LOST'."
            );
        }

        if (in_array($dispute->status, [Dispute::STATUS_WON, Dispute::STATUS_LOST], true)) {
            throw new UnprocessableEntityHttpException(
                "Dispute [{$dispute->reference}] is already resolved with outcome [{$dispute->status}]."
            );
        }

        return DB::transaction(function () use ($dispute, $outcome, $resolutionNote) {
            $dispute->update([
                'status' => $outcome,
                'resolved_at' => now(),
                'resolution_note' => $resolutionNote,
            ]);

            $transaction = $dispute->transaction;
            $merchantWon = ($outcome === Dispute::STATUS_WON);

            // Update Transaction State
            $prevStatus = $transaction->status;
            $newStatus = $merchantWon
                ? ($transaction->totalRefundedAmount() > 0 ? Transaction::STATUS_PARTIALLY_REFUNDED : Transaction::STATUS_SUCCESS)
                : Transaction::STATUS_REVERSED;

            $transaction->update(['status' => $newStatus]);

            TransactionEvent::create([
                'transaction_id' => $transaction->id,
                'from_status' => $prevStatus,
                'to_status' => $newStatus,
                'event_type' => $merchantWon ? 'DISPUTE_RESOLVED_WON' : 'DISPUTE_RESOLVED_LOST',
                'triggered_by' => 'DISPUTE_ENGINE',
                'payload' => [
                    'dispute_reference' => $dispute->reference,
                    'outcome' => $outcome,
                    'note' => $resolutionNote,
                ],
            ]);

            // Release or forfeit ledger dispute escrow reserve
            $this->ledgerService->recordDisputeResolution($dispute, $merchantWon);

            // Audit Log
            $this->auditLogService->logPaymentEvent($transaction, $merchantWon ? 'DISPUTE_WON' : 'DISPUTE_LOST', [
                'dispute_reference' => $dispute->reference,
                'resolution_note' => $resolutionNote,
            ]);

            // Webhook
            $this->webhookDispatcher->dispatchTransactionWebhook(
                $transaction,
                $merchantWon ? 'dispute.won' : 'dispute.lost',
                [
                    'dispute_reference' => $dispute->reference,
                    'outcome' => $outcome,
                    'resolution_note' => $resolutionNote,
                ]
            );

            return $dispute->fresh(['transaction']);
        });
    }
}
