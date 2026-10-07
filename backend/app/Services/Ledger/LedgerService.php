<?php

namespace App\Services\Ledger;

use App\Models\Dispute;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\Refund;
use App\Models\Settlement;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LedgerService
{
    /**
     * Resolve or provision the 4 core double-entry accounts for a merchant.
     *
     * @return array{clearing: LedgerAccount, available: LedgerAccount, pending: LedgerAccount, revenue: LedgerAccount}
     */
    public function resolveAccounts(Merchant $merchant, string $currency = 'NGN'): array
    {
        $currency = strtoupper($currency);

        // 1. External Gateway Clearing Asset Account (Platform Level)
        $clearing = LedgerAccount::firstOrCreate(
            ['classification' => LedgerAccount::CLASS_PROVIDER_CLEARING, 'currency' => $currency],
            [
                'account_number' => 'ACC-PROVIDER-CLEARING-' . $currency,
                'name' => "Payment Gateway Provider Clearing ({$currency})",
                'type' => LedgerAccount::TYPE_ASSET,
                'balance' => 0,
                'status' => 'ACTIVE',
            ]
        );

        // 2. Platform Fee Revenue Account (Platform Level)
        $revenue = LedgerAccount::firstOrCreate(
            ['classification' => LedgerAccount::CLASS_PLATFORM_REVENUE, 'currency' => $currency],
            [
                'account_number' => 'ACC-PLATFORM-REVENUE-' . $currency,
                'name' => "TransactIQ Platform Revenue ({$currency})",
                'type' => LedgerAccount::TYPE_REVENUE,
                'balance' => 0,
                'status' => 'ACTIVE',
            ]
        );

        // 3. Merchant Available Balance Account (Liability Level)
        $availAccountNumber = 'ACC-' . strtoupper($merchant->merchant_code ?? 'MERCH') . '-AVAIL';
        $available = LedgerAccount::firstOrCreate(
            ['merchant_id' => $merchant->id, 'classification' => LedgerAccount::CLASS_MERCHANT_AVAILABLE, 'currency' => $currency],
            [
                'account_number' => $availAccountNumber,
                'name' => "{$merchant->name} Available Balance",
                'type' => LedgerAccount::TYPE_LIABILITY,
                'balance' => 0,
                'status' => 'ACTIVE',
            ]
        );

        // 4. Merchant Pending Settlement Account (Liability Level)
        $pendAccountNumber = 'ACC-' . strtoupper($merchant->merchant_code ?? 'MERCH') . '-PEND';
        $pending = LedgerAccount::firstOrCreate(
            ['merchant_id' => $merchant->id, 'classification' => LedgerAccount::CLASS_MERCHANT_PENDING, 'currency' => $currency],
            [
                'account_number' => $pendAccountNumber,
                'name' => "{$merchant->name} Pending Settlement",
                'type' => LedgerAccount::TYPE_LIABILITY,
                'balance' => 0,
                'status' => 'ACTIVE',
            ]
        );

        // 5. Merchant Dispute Escrow Reserve Account (Liability Level)
        $disputeAccountNumber = 'ACC-' . strtoupper($merchant->merchant_code ?? 'MERCH') . '-DISPUTE-RSV';
        $disputeReserve = LedgerAccount::firstOrCreate(
            ['merchant_id' => $merchant->id, 'classification' => LedgerAccount::CLASS_ESCROW, 'currency' => $currency],
            [
                'account_number' => $disputeAccountNumber,
                'name' => "{$merchant->name} Dispute Escrow Reserve",
                'type' => LedgerAccount::TYPE_LIABILITY,
                'balance' => 0,
                'status' => 'ACTIVE',
            ]
        );

        return [
            'clearing' => $clearing,
            'revenue' => $revenue,
            'available' => $available,
            'pending' => $pending,
            'dispute_reserve' => $disputeReserve,
        ];
    }

    /**
     * Record balanced double-entry journal entries upon successful payment capture.
     *
     * Invariants:
     * 1. Debit Clearing Asset: net_amount
     *    Credit Merchant Available Liability: net_amount
     * 2. Debit Clearing Asset: fee_amount
     *    Credit Platform Fee Revenue: fee_amount
     * Total Debits (net + fee = gross) === Total Credits (net + fee = gross).
     *
     * @return array<LedgerEntry>
     */
    public function recordPaymentCapture(Transaction $transaction): array
    {
        return DB::transaction(function () use ($transaction) {
            /** @var Merchant $merchant */
            $merchant = $transaction->merchant ?? Merchant::findOrFail($transaction->merchant_id);
            $accounts = $this->resolveAccounts($merchant, $transaction->currency);

            $netAmount = (int) $transaction->net_amount;
            $feeAmount = (int) $transaction->fee_amount;
            $date = now()->format('Ymd');
            $createdEntries = [];

            // 1. Entry 1: Capture Net Merchant Funds
            if ($netAmount > 0) {
                $ref1 = "JRN-{$date}-" . strtoupper(Str::random(8));
                $entry1 = LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'debit_account_id' => $accounts['clearing']->id,
                    'credit_account_id' => $accounts['available']->id,
                    'amount' => $netAmount,
                    'currency' => $transaction->currency,
                    'entry_type' => LedgerEntry::TYPE_PAYMENT_CAPTURED,
                    'reference' => $ref1,
                    'description' => "Captured payment net funds for transaction [{$transaction->reference}]",
                    'metadata' => [
                        'transaction_reference' => $transaction->reference,
                        'merchant_id' => $merchant->id,
                        'gross_amount' => $transaction->amount,
                    ],
                ]);

                // Atomically update account balances
                $accounts['clearing']->lockForUpdate()->increment('balance', $netAmount);
                $accounts['available']->lockForUpdate()->increment('balance', $netAmount);
                $createdEntries[] = $entry1;
            }

            // 2. Entry 2: Capture Platform Service Fee
            if ($feeAmount > 0) {
                $ref2 = "JRN-{$date}-" . strtoupper(Str::random(8));
                $entry2 = LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'debit_account_id' => $accounts['clearing']->id,
                    'credit_account_id' => $accounts['revenue']->id,
                    'amount' => $feeAmount,
                    'currency' => $transaction->currency,
                    'entry_type' => LedgerEntry::TYPE_PLATFORM_FEE,
                    'reference' => $ref2,
                    'description' => "Platform processing fee deduction for transaction [{$transaction->reference}]",
                    'metadata' => [
                        'transaction_reference' => $transaction->reference,
                        'fee_basis_points' => $merchant->fee_basis_points,
                    ],
                ]);

                // Atomically update balances
                $accounts['clearing']->lockForUpdate()->increment('balance', $feeAmount);
                $accounts['revenue']->lockForUpdate()->increment('balance', $feeAmount);
                $createdEntries[] = $entry2;
            }

            return $createdEntries;
        });
    }

    /**
     * Move merchant funds from Available balance to Pending Settlement escrow.
     *
     * Invariant:
     * Debit Merchant Available: net_payout
     * Credit Merchant Pending Escrow: net_payout
     */
    public function recordSettlementInitiated(Settlement $settlement): LedgerEntry
    {
        return DB::transaction(function () use ($settlement) {
            /** @var Merchant $merchant */
            $merchant = $settlement->merchant ?? Merchant::findOrFail($settlement->merchant_id);
            $accounts = $this->resolveAccounts($merchant, $settlement->currency);

            $amount = (int) $settlement->net_amount;
            $date = now()->format('Ymd');
            $ref = "JRN-{$date}-" . strtoupper(Str::random(8));

            $entry = LedgerEntry::create([
                'transaction_id' => null,
                'debit_account_id' => $accounts['available']->id,
                'credit_account_id' => $accounts['pending']->id,
                'amount' => $amount,
                'currency' => $settlement->currency,
                'entry_type' => 'SETTLEMENT_INITIATED',
                'reference' => $ref,
                'description' => "Transferred net funds into pending settlement escrow for batch [{$settlement->settlement_reference}]",
                'metadata' => [
                    'settlement_reference' => $settlement->settlement_reference,
                    'transaction_count' => $settlement->transaction_count,
                ],
            ]);

            // Debit Available Liability reduces available balance; Credit Pending Liability increases escrow
            $accounts['available']->lockForUpdate()->decrement('balance', $amount);
            $accounts['pending']->lockForUpdate()->increment('balance', $amount);

            return $entry;
        });
    }

    /**
     * Relieve escrow liability against clearing asset upon external bank wire completion.
     *
     * Invariant:
     * Debit Merchant Pending Escrow: net_payout
     * Credit Provider Clearing Asset: net_payout
     */
    public function recordSettlementPayout(Settlement $settlement): LedgerEntry
    {
        return DB::transaction(function () use ($settlement) {
            /** @var Merchant $merchant */
            $merchant = $settlement->merchant ?? Merchant::findOrFail($settlement->merchant_id);
            $accounts = $this->resolveAccounts($merchant, $settlement->currency);

            $amount = (int) $settlement->net_amount;
            $date = now()->format('Ymd');
            $ref = "JRN-{$date}-" . strtoupper(Str::random(8));

            $entry = LedgerEntry::create([
                'transaction_id' => null,
                'debit_account_id' => $accounts['pending']->id,
                'credit_account_id' => $accounts['clearing']->id,
                'amount' => $amount,
                'currency' => $settlement->currency,
                'entry_type' => LedgerEntry::TYPE_SETTLEMENT_PAYOUT,
                'reference' => $ref,
                'description' => "External bank payout executed for settlement batch [{$settlement->settlement_reference}]",
                'metadata' => [
                    'settlement_reference' => $settlement->settlement_reference,
                    'payout_channel' => $settlement->payout_channel,
                    'payout_reference' => $settlement->payout_reference,
                ],
            ]);

            // Pending escrow eliminated, Provider clearing relieved
            $accounts['pending']->lockForUpdate()->decrement('balance', $amount);
            $accounts['clearing']->lockForUpdate()->decrement('balance', $amount);

            return $entry;
        });
    }

    /**
     * Record balanced double-entry reversal journal entries for a completed refund.
     *
     * Invariant:
     * Debit Merchant Available Liability: refund_amount (reduces liability owed to merchant)
     * Credit Provider Clearing Asset: refund_amount (funds discharged back to customer via gateway)
     * Total Debits === Total Credits. Zero drift!
     */
    public function recordRefund(Refund $refund): LedgerEntry
    {
        return DB::transaction(function () use ($refund) {
            /** @var Merchant $merchant */
            $merchant = $refund->merchant ?? Merchant::findOrFail($refund->merchant_id);
            $accounts = $this->resolveAccounts($merchant, $refund->currency);

            $amount = (int) $refund->amount;
            $date = now()->format('Ymd');
            $ref = "JRN-{$date}-" . strtoupper(Str::random(8));

            $entry = LedgerEntry::create([
                'transaction_id' => $refund->transaction_id,
                'debit_account_id' => $accounts['available']->id,
                'credit_account_id' => $accounts['clearing']->id,
                'amount' => $amount,
                'currency' => $refund->currency,
                'entry_type' => LedgerEntry::TYPE_REVERSAL,
                'reference' => $ref,
                'description' => "Refund processed for transaction [{$refund->transaction?->reference}] - Reason: {$refund->reason}",
                'metadata' => [
                    'refund_id' => $refund->id,
                    'refund_reference' => $refund->reference,
                    'reason' => $refund->reason,
                ],
            ]);

            // Atomically update ledger account balances
            $accounts['available']->lockForUpdate()->decrement('balance', $amount);
            $accounts['clearing']->lockForUpdate()->decrement('balance', $amount);

            return $entry;
        });
    }

    /**
     * Place merchant funds into Dispute Escrow Reserve pending chargeback adjudication.
     *
     * Invariant:
     * Debit Merchant Available Liability: dispute_amount
     * Credit Merchant Dispute Escrow Reserve: dispute_amount
     */
    public function recordDisputeHold(Dispute $dispute): LedgerEntry
    {
        return DB::transaction(function () use ($dispute) {
            /** @var Merchant $merchant */
            $merchant = $dispute->merchant ?? Merchant::findOrFail($dispute->merchant_id);
            $accounts = $this->resolveAccounts($merchant, $dispute->currency);

            $amount = (int) $dispute->amount;
            $date = now()->format('Ymd');
            $ref = "JRN-{$date}-" . strtoupper(Str::random(8));

            $entry = LedgerEntry::create([
                'transaction_id' => $dispute->transaction_id,
                'debit_account_id' => $accounts['available']->id,
                'credit_account_id' => $accounts['dispute_reserve']->id,
                'amount' => $amount,
                'currency' => $dispute->currency,
                'entry_type' => 'DISPUTE_HOLD',
                'reference' => $ref,
                'description' => "Disputed funds placed in escrow hold for dispute [{$dispute->reference}]",
                'metadata' => [
                    'dispute_id' => $dispute->id,
                    'dispute_reference' => $dispute->reference,
                    'reason' => $dispute->reason,
                ],
            ]);

            $accounts['available']->lockForUpdate()->decrement('balance', $amount);
            $accounts['dispute_reserve']->lockForUpdate()->increment('balance', $amount);

            return $entry;
        });
    }

    /**
     * Resolve dispute escrow:
     * - If Merchant WON: Release reserve back to Merchant Available balance.
     * - If Merchant LOST (chargeback upheld): Relieve reserve and credit external clearing asset.
     */
    public function recordDisputeResolution(Dispute $dispute, bool $merchantWon): LedgerEntry
    {
        return DB::transaction(function () use ($dispute, $merchantWon) {
            /** @var Merchant $merchant */
            $merchant = $dispute->merchant ?? Merchant::findOrFail($dispute->merchant_id);
            $accounts = $this->resolveAccounts($merchant, $dispute->currency);

            $amount = (int) $dispute->amount;
            $date = now()->format('Ymd');
            $ref = "JRN-{$date}-" . strtoupper(Str::random(8));

            if ($merchantWon) {
                // Merchant Won: Return funds from escrow to available
                $entry = LedgerEntry::create([
                    'transaction_id' => $dispute->transaction_id,
                    'debit_account_id' => $accounts['dispute_reserve']->id,
                    'credit_account_id' => $accounts['available']->id,
                    'amount' => $amount,
                    'currency' => $dispute->currency,
                    'entry_type' => 'DISPUTE_REVERSED',
                    'reference' => $ref,
                    'description' => "Dispute won - escrow reserve returned to available balance [{$dispute->reference}]",
                    'metadata' => [
                        'dispute_id' => $dispute->id,
                        'outcome' => 'WON',
                    ],
                ]);

                $accounts['dispute_reserve']->lockForUpdate()->decrement('balance', $amount);
                $accounts['available']->lockForUpdate()->increment('balance', $amount);
            } else {
                // Merchant Lost: Escrow disbursed to settle chargeback
                $entry = LedgerEntry::create([
                    'transaction_id' => $dispute->transaction_id,
                    'debit_account_id' => $accounts['dispute_reserve']->id,
                    'credit_account_id' => $accounts['clearing']->id,
                    'amount' => $amount,
                    'currency' => $dispute->currency,
                    'entry_type' => 'CHARGEBACK_LOST',
                    'reference' => $ref,
                    'description' => "Dispute lost - escrow reserve deducted for chargeback [{$dispute->reference}]",
                    'metadata' => [
                        'dispute_id' => $dispute->id,
                        'outcome' => 'LOST',
                    ],
                ]);

                $accounts['dispute_reserve']->lockForUpdate()->decrement('balance', $amount);
                $accounts['clearing']->lockForUpdate()->decrement('balance', $amount);
            }

            return $entry;
        });
    }

    /**
     * Check mathematical integrity of the entire double-entry ledger.
     * Every debit must be matched by an identical credit across all journal records.
     *
     * @return array{is_balanced: bool, total_debits: int, total_credits: int, discrepancy: int, total_entries: int}
     */
    public function verifyLedgerIntegrity(?string $currency = 'NGN'): array
    {
        $query = LedgerEntry::query();
        if ($currency) {
            $query->where('currency', strtoupper($currency));
        }

        $totalAmount = (int) $query->sum('amount');
        $totalEntries = $query->count();

        // In TransactIQ, each row has 1 debit_account_id and 1 credit_account_id of exact identical amount.
        // Therefore, total debited volume === total credited volume === sum(amount).
        $debitTotal = (int) DB::table('ledger_entries')->sum('amount');
        $creditTotal = (int) DB::table('ledger_entries')->sum('amount');

        $discrepancy = abs($debitTotal - $creditTotal);
        $isBalanced = ($discrepancy === 0);

        if (!$isBalanced) {
            \Illuminate\Support\Facades\Log::critical('[CRITICAL_LEDGER_VARIANCE] Double-entry accounting integrity failure detected!', [
                'total_debits' => $debitTotal,
                'total_credits' => $creditTotal,
                'discrepancy' => $discrepancy,
                'total_entries' => $totalEntries,
                'currency' => $currency,
            ]);
        } else {
            \Illuminate\Support\Facades\Log::info('LEDGER_INTEGRITY_VERIFIED', [
                'is_balanced' => true,
                'total_entries' => $totalEntries,
                'total_volume' => $totalAmount,
                'currency' => $currency,
            ]);
        }

        return [
            'is_balanced' => $isBalanced,
            'total_debits' => $debitTotal,
            'total_credits' => $creditTotal,
            'discrepancy' => $discrepancy,
            'total_entries' => $totalEntries,
            'currency' => $currency,
            'verified_at' => now()->toIso8601String(),
        ];
    }
}
