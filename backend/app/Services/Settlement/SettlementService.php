<?php

namespace App\Services\Settlement;

use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Services\Ledger\LedgerService;
use App\Services\Security\AuditLogService;
use App\Services\Webhook\WebhookDispatcherService;
use App\Services\Webhook\WebhookSignerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettlementService
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected ?WebhookDispatcherService $webhookDispatcher = null,
        protected ?WebhookSignerService $signer = null,
        protected ?AuditLogService $auditLogService = null
    ) {
        $this->webhookDispatcher = $webhookDispatcher ?? app(WebhookDispatcherService::class);
        $this->signer = $signer ?? app(WebhookSignerService::class);
        $this->auditLogService = $auditLogService ?? app(AuditLogService::class);
    }

    /**
     * Group all eligible cleared transactions into a single settlement batch.
     */
    public function generateSettlementBatch(Merchant $merchant, string $currency = 'NGN'): ?Settlement
    {
        return DB::transaction(function () use ($merchant, $currency) {
            $currency = strtoupper($currency);

            // 1. Fetch all successful transactions not yet assigned to a settlement batch
            $eligibleTxns = Transaction::where('merchant_id', $merchant->id)
                ->where('currency', $currency)
                ->where('status', Transaction::STATUS_SUCCESS)
                ->whereNull('settlement_id')
                ->lockForUpdate()
                ->get();

            if ($eligibleTxns->isEmpty()) {
                return null;
            }

            $grossAmount = (int) $eligibleTxns->sum('amount');
            $feeAmount = (int) $eligibleTxns->sum('fee_amount');
            $netAmount = (int) $eligibleTxns->sum('net_amount');
            $count = $eligibleTxns->count();

            $date = now()->format('Ymd');
            $ref = "SETTLE-{$date}-" . strtoupper(Str::random(6));

            $bankDetails = $merchant->settlement_bank_details ?? [];
            $channelDesc = !empty($bankDetails['bank_name'])
                ? "{$bankDetails['bank_name']} ({$bankDetails['account_number']})"
                : 'Direct Bank Transfer';

            // 2. Create Settlement Batch Record
            $settlement = Settlement::create([
                'merchant_id' => $merchant->id,
                'settlement_reference' => $ref,
                'gross_amount' => $grossAmount,
                'fee_amount' => $feeAmount,
                'net_amount' => $netAmount,
                'currency' => $currency,
                'status' => Settlement::STATUS_PENDING,
                'transaction_count' => $count,
                'payout_channel' => $channelDesc,
                'metadata' => [
                    'cycle' => 'T+1',
                    'generated_at' => now()->toIso8601String(),
                    'bank_details' => $bankDetails,
                ],
            ]);

            // 3. Mark transactions as attached to this settlement
            Transaction::whereIn('id', $eligibleTxns->pluck('id'))
                ->update(['settlement_id' => $settlement->id]);

            // 4. Record Double-Entry Journal Entry: Move from Available Balance to Settlement Escrow
            $this->ledgerService->recordSettlementInitiated($settlement);

            // 5. Record Tamper-Evident Audit Log
            $this->auditLogService->logSettlementEvent($settlement, 'SETTLEMENT_BATCH_GENERATED');

            return $settlement->load('transactions');
        });
    }

    /**
     * Mark an external bank wire payout as executed and completed.
     */
    public function completeSettlementPayout(Settlement $settlement, ?string $payoutRef = null): Settlement
    {
        return DB::transaction(function () use ($settlement, $payoutRef) {
            if ($settlement->status === Settlement::STATUS_COMPLETED) {
                return $settlement;
            }

            $ref = $payoutRef ?: ('WIRE-' . strtoupper(Str::random(10)));

            // 1. Relieve Escrow Liability against Clearing Asset
            $this->ledgerService->recordSettlementPayout($settlement);

            // 2. Update Settlement Record
            $settlement->update([
                'status' => Settlement::STATUS_COMPLETED,
                'payout_reference' => $ref,
                'payout_date' => now(),
            ]);

            // 3. Record Tamper-Evident Audit Log
            $this->auditLogService->logSettlementEvent($settlement, 'SETTLEMENT_PAYOUT_EXECUTED', [
                'payout_reference' => $ref,
            ]);

            // 4. Dispatch settlement.completed webhook to merchant
            $this->dispatchSettlementWebhook($settlement);

            return $settlement->fresh(['transactions', 'merchant']);
        });
    }

    /**
     * Dispatch cryptographically signed settlement.completed webhook.
     */
    protected function dispatchSettlementWebhook(Settlement $settlement): ?WebhookDelivery
    {
        /** @var Merchant $merchant */
        $merchant = $settlement->merchant ?? Merchant::find($settlement->merchant_id);
        if (!$merchant || empty($merchant->webhook_url)) {
            return null;
        }

        $payload = [
            'event_id' => 'evt_' . (string) Str::uuid(),
            'event_type' => 'settlement.completed',
            'created_at' => now()->toIso8601String(),
            'data' => [
                'settlement_id' => $settlement->id,
                'settlement_reference' => $settlement->settlement_reference,
                'merchant_id' => $merchant->id,
                'gross_amount' => $settlement->gross_amount,
                'fee_amount' => $settlement->fee_amount,
                'net_amount' => $settlement->net_amount,
                'currency' => $settlement->currency,
                'transaction_count' => $settlement->transaction_count,
                'payout_reference' => $settlement->payout_reference,
                'payout_date' => $settlement->payout_date?->toIso8601String(),
            ],
        ];

        $sigInfo = $this->signer->generateSignature($payload, $merchant->webhook_secret ?: 'whsec_default');

        $delivery = WebhookDelivery::create([
            'merchant_id' => $merchant->id,
            'transaction_id' => null,
            'event_type' => 'settlement.completed',
            'endpoint_url' => $merchant->webhook_url,
            'signature' => $sigInfo['header'],
            'payload' => $payload,
            'attempts' => 0,
            'max_attempts' => 5,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        \App\Jobs\DispatchWebhookJob::dispatch($delivery->id);

        return $delivery;
    }
}
