<?php

namespace App\Services\Reconciliation;

use App\Models\Merchant;
use App\Models\ReconciliationException;
use App\Models\ReconciliationRun;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use App\Services\Security\AuditLogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconciliationService
{
    public function __construct(
        protected ClearingFileParserService $fileParser,
        protected LedgerService $ledgerService,
        protected ?AuditLogService $auditLogService = null
    ) {
        $this->auditLogService = $auditLogService ?? app(AuditLogService::class);
    }

    /**
     * Ingest and reconcile clearing file content against internal transaction records.
     *
     * @param Merchant $merchant
     * @param array $providerRecords Pre-parsed or parsed records
     * @param string $provider Name of provider (e.g. SIMULATED_GATEWAY, PAYSTACK)
     * @param string|null $sourceFileName
     * @param string|null $reconciliationDate Defaults to today
     * @return ReconciliationRun
     */
    public function processReconciliation(
        Merchant $merchant,
        array $providerRecords,
        string $provider = 'SIMULATED_GATEWAY',
        ?string $sourceFileName = null,
        ?string $reconciliationDate = null
    ): ReconciliationRun {
        $date = $reconciliationDate ? Carbon::parse($reconciliationDate)->toDateString() : now()->toDateString();
        $runRef = 'REC-' . Carbon::parse($date)->format('Ymd') . '-' . strtoupper(Str::random(6));

        return DB::transaction(function () use ($merchant, $providerRecords, $provider, $sourceFileName, $date, $runRef) {
            /** @var ReconciliationRun $run */
            $run = ReconciliationRun::create([
                'run_reference' => $runRef,
                'provider' => $provider,
                'source_file' => $sourceFileName,
                'reconciliation_date' => $date,
                'total_internal_records' => 0,
                'total_provider_records' => count($providerRecords),
                'matched_records' => 0,
                'mismatched_records' => 0,
                'matched_volume_minor' => 0,
                'mismatched_volume_minor' => 0,
                'status' => ReconciliationRun::STATUS_PROCESSING,
            ]);

            // Query candidate internal transactions for the merchant.
            // Retrieve transactions created on the reconciliation date or matching any provider/transaction references in the file.
            $providerRefs = array_filter(array_column($providerRecords, 'provider_reference'));
            $txRefs = array_filter(array_column($providerRecords, 'transaction_reference'));

            $internalTxns = Transaction::where('merchant_id', $merchant->id)
                ->where(function ($q) use ($date, $providerRefs, $txRefs) {
                    $q->whereDate('created_at', $date);
                    if (!empty($providerRefs)) {
                        $q->orWhereIn('provider_reference', $providerRefs);
                    }
                    if (!empty($txRefs)) {
                        $q->orWhereIn('reference', $txRefs);
                    }
                })
                ->get();

            $run->update(['total_internal_records' => $internalTxns->count()]);

            // Index internal transactions by provider_reference and reference for O(1) lookups
            $byProviderRef = [];
            $byReference = [];
            foreach ($internalTxns as $txn) {
                if ($txn->provider_reference) {
                    $byProviderRef[$txn->provider_reference] = $txn;
                }
                $byReference[$txn->reference] = $txn;
            }

            $checkedInternalIds = [];
            $matchedRecords = 0;
            $mismatchedRecords = 0;
            $matchedVolume = 0;
            $mismatchedVolume = 0;
            $exceptionCountByType = [
                ReconciliationException::TYPE_MISSING_IN_INTERNAL => 0,
                ReconciliationException::TYPE_MISSING_IN_PROVIDER => 0,
                ReconciliationException::TYPE_AMOUNT_MISMATCH => 0,
                ReconciliationException::TYPE_STATUS_MISMATCH => 0,
            ];

            // 1. Forward Pass: Provider Clearing Records -> Internal Transactions
            foreach ($providerRecords as $rec) {
                $pRef = $rec['provider_reference'] ?? null;
                $tRef = $rec['transaction_reference'] ?? null;
                $pAmount = (int) ($rec['amount'] ?? 0);
                $pStatus = $rec['status'] ?? 'SUCCESS';

                /** @var Transaction|null $internalTxn */
                $internalTxn = ($pRef && isset($byProviderRef[$pRef]))
                    ? $byProviderRef[$pRef]
                    : (($tRef && isset($byReference[$tRef])) ? $byReference[$tRef] : null);

                if (!$internalTxn) {
                    // Record exists in external provider file but missing in internal database
                    ReconciliationException::create([
                        'reconciliation_run_id' => $run->id,
                        'transaction_id' => null,
                        'internal_reference' => $tRef,
                        'provider_reference' => $pRef,
                        'exception_type' => ReconciliationException::TYPE_MISSING_IN_INTERNAL,
                        'internal_amount_minor' => null,
                        'provider_amount_minor' => $pAmount,
                        'internal_status' => null,
                        'provider_status' => $pStatus,
                        'discrepancy_details' => [
                            'reason' => 'Transaction settled by upstream payment gateway but not found in TransactIQ database.',
                            'raw_provider_record' => $rec['raw_data'] ?? null,
                        ],
                        'status' => ReconciliationException::STATUS_OPEN,
                    ]);

                    $mismatchedRecords++;
                    $mismatchedVolume += $pAmount;
                    $exceptionCountByType[ReconciliationException::TYPE_MISSING_IN_INTERNAL]++;
                    continue;
                }

                $checkedInternalIds[$internalTxn->id] = true;

                // Check Amount
                if ($internalTxn->amount !== $pAmount) {
                    ReconciliationException::create([
                        'reconciliation_run_id' => $run->id,
                        'transaction_id' => $internalTxn->id,
                        'internal_reference' => $internalTxn->reference,
                        'provider_reference' => $pRef ?: $internalTxn->provider_reference,
                        'exception_type' => ReconciliationException::TYPE_AMOUNT_MISMATCH,
                        'internal_amount_minor' => $internalTxn->amount,
                        'provider_amount_minor' => $pAmount,
                        'internal_status' => $internalTxn->status,
                        'provider_status' => $pStatus,
                        'discrepancy_details' => [
                            'variance_minor' => $internalTxn->amount - $pAmount,
                            'variance_formatted' => ($internalTxn->amount - $pAmount) / 100,
                            'reason' => 'Internal transaction amount differs from provider settled amount.',
                        ],
                        'status' => ReconciliationException::STATUS_OPEN,
                    ]);

                    $mismatchedRecords++;
                    $mismatchedVolume += abs($internalTxn->amount - $pAmount);
                    $exceptionCountByType[ReconciliationException::TYPE_AMOUNT_MISMATCH]++;
                    continue;
                }

                // Check Status
                if ($internalTxn->status !== $pStatus) {
                    // Auto-Resolution: Internal transaction was in PENDING, and provider confirms SUCCESS
                    if ($internalTxn->status === 'PENDING' && $pStatus === 'SUCCESS') {
                        $internalTxn->update([
                            'status' => 'SUCCESS',
                            'provider_reference' => $pRef ?: $internalTxn->provider_reference,
                        ]);

                        // Post balanced double-entry ledger capture
                        $this->ledgerService->recordPaymentCapture($internalTxn);

                        ReconciliationException::create([
                            'reconciliation_run_id' => $run->id,
                            'transaction_id' => $internalTxn->id,
                            'internal_reference' => $internalTxn->reference,
                            'provider_reference' => $pRef ?: $internalTxn->provider_reference,
                            'exception_type' => ReconciliationException::TYPE_STATUS_MISMATCH,
                            'internal_amount_minor' => $internalTxn->amount,
                            'provider_amount_minor' => $pAmount,
                            'internal_status' => 'PENDING',
                            'provider_status' => 'SUCCESS',
                            'discrepancy_details' => [
                                'auto_recovery' => true,
                                'action' => 'Transitioned PENDING -> SUCCESS and captured funds in double-entry ledger.',
                            ],
                            'status' => ReconciliationException::STATUS_RESOLVED,
                            'resolution_notes' => 'Auto-resolved: Provider clearing report confirmed SUCCESS. Ledger capture executed.',
                            'resolved_at' => now(),
                        ]);

                        $matchedRecords++;
                        $matchedVolume += $internalTxn->amount;
                        continue;
                    }

                    // Status mismatch that cannot be auto-resolved
                    ReconciliationException::create([
                        'reconciliation_run_id' => $run->id,
                        'transaction_id' => $internalTxn->id,
                        'internal_reference' => $internalTxn->reference,
                        'provider_reference' => $pRef ?: $internalTxn->provider_reference,
                        'exception_type' => ReconciliationException::TYPE_STATUS_MISMATCH,
                        'internal_amount_minor' => $internalTxn->amount,
                        'provider_amount_minor' => $pAmount,
                        'internal_status' => $internalTxn->status,
                        'provider_status' => $pStatus,
                        'discrepancy_details' => [
                            'reason' => "Status divergence: internal is [{$internalTxn->status}], provider is [{$pStatus}].",
                        ],
                        'status' => ReconciliationException::STATUS_OPEN,
                    ]);

                    $mismatchedRecords++;
                    $mismatchedVolume += $internalTxn->amount;
                    $exceptionCountByType[ReconciliationException::TYPE_STATUS_MISMATCH]++;
                    continue;
                }

                // Reference, Amount, and Status all match cleanly
                $matchedRecords++;
                $matchedVolume += $internalTxn->amount;
            }

            // 2. Reverse Pass: Internal SUCCESS transactions for this date missing from provider clearing report
            foreach ($internalTxns as $txn) {
                if ($txn->status === 'SUCCESS' && $txn->created_at->toDateString() === $date && !isset($checkedInternalIds[$txn->id])) {
                    ReconciliationException::create([
                        'reconciliation_run_id' => $run->id,
                        'transaction_id' => $txn->id,
                        'internal_reference' => $txn->reference,
                        'provider_reference' => $txn->provider_reference,
                        'exception_type' => ReconciliationException::TYPE_MISSING_IN_PROVIDER,
                        'internal_amount_minor' => $txn->amount,
                        'provider_amount_minor' => null,
                        'internal_status' => $txn->status,
                        'provider_status' => null,
                        'discrepancy_details' => [
                            'reason' => 'Internal database recorded successful payment, but omitted from external provider clearing report.',
                        ],
                        'status' => ReconciliationException::STATUS_OPEN,
                    ]);

                    $mismatchedRecords++;
                    $mismatchedVolume += $txn->amount;
                    $exceptionCountByType[ReconciliationException::TYPE_MISSING_IN_PROVIDER]++;
                }
            }

            // Calculate overall match metrics
            $totalEvaluated = $matchedRecords + $mismatchedRecords;
            $matchRate = $totalEvaluated > 0 ? round(($matchedRecords / $totalEvaluated) * 100, 2) : 100.0;

            $run->update([
                'matched_records' => $matchedRecords,
                'mismatched_records' => $mismatchedRecords,
                'matched_volume_minor' => $matchedVolume,
                'mismatched_volume_minor' => $mismatchedVolume,
                'status' => ReconciliationRun::STATUS_COMPLETED,
                'summary' => [
                    'match_rate_percent' => $matchRate,
                    'total_evaluated' => $totalEvaluated,
                    'exceptions_by_type' => $exceptionCountByType,
                    'reconciliation_mode' => 'TWO_WAY_AUTOMATED',
                    'completed_at' => now()->toIso8601String(),
                ],
            ]);

            // Record Tamper-Evident Audit Log
            $this->auditLogService->logReconciliationEvent($run, 'RECONCILIATION_RUN_COMPLETED', [
                'run_reference' => $run->run_reference,
                'provider' => $run->provider,
                'matched_records' => $matchedRecords,
                'mismatched_records' => $mismatchedRecords,
                'match_rate_percent' => $matchRate,
            ]);

            return $run->fresh(['exceptions']);
        });
    }

    /**
     * Triage or resolve a reconciliation exception.
     */
    public function resolveException(
        ReconciliationException $exception,
        string $action,
        ?string $notes = null,
        ?int $userId = null
    ): ReconciliationException {
        return DB::transaction(function () use ($exception, $action, $notes, $userId) {
            $normalizedAction = strtoupper(trim($action));

            if ($normalizedAction === 'FORCE_SUCCESS' && $exception->transaction) {
                $txn = $exception->transaction;
                if ($txn->status !== 'SUCCESS') {
                    $txn->update(['status' => 'SUCCESS']);
                    $this->ledgerService->recordPaymentCapture($txn);
                }
                $normalizedAction = ReconciliationException::STATUS_RESOLVED;
            }

            $exception->update([
                'status' => in_array($normalizedAction, [
                    ReconciliationException::STATUS_RESOLVED,
                    ReconciliationException::STATUS_INVESTIGATING,
                    ReconciliationException::STATUS_WRITTEN_OFF,
                ], true) ? $normalizedAction : ReconciliationException::STATUS_RESOLVED,
                'resolution_notes' => $notes ?: 'Exception resolved via operations triage.',
                'resolved_by_user_id' => $userId,
                'resolved_at' => now(),
            ]);

            // Record Tamper-Evident Audit Log
            $this->auditLogService->logReconciliationEvent($exception, 'RECONCILIATION_EXCEPTION_RESOLVED', [
                'exception_id' => $exception->id,
                'action' => $action,
                'resolved_by_user_id' => $userId,
                'notes' => $notes,
            ]);

            return $exception->fresh();
        });
    }

    /**
     * Generate realistic simulated provider clearing data for instant testing and sandbox demonstration.
     */
    public function generateSampleClearingFile(Merchant $merchant, string $format = 'csv'): string
    {
        $recentTxns = Transaction::where('merchant_id', $merchant->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        $rows = [];

        // 1. Perfect match rows
        foreach ($recentTxns as $idx => $t) {
            if ($idx >= 2) break;
            $rows[] = [
                'provider_reference' => $t->provider_reference ?: ('PROV-' . Str::random(12)),
                'transaction_reference' => $t->reference,
                'amount' => number_format($t->amount / 100, 2, '.', ''),
                'fee' => number_format($t->fee_amount / 100, 2, '.', ''),
                'currency' => $t->currency,
                'status' => 'SUCCESS',
                'paid_at' => $t->created_at->format('Y-m-d H:i:s'),
                'channel' => $t->payment_method,
            ];
        }

        // 2. Amount Mismatch scenario
        $sampleTxn = $recentTxns->skip(2)->first() ?: $recentTxns->first();
        if ($sampleTxn) {
            $rows[] = [
                'provider_reference' => $sampleTxn->provider_reference ?: ('PROV-MISMATCH-' . Str::random(8)),
                'transaction_reference' => $sampleTxn->reference,
                'amount' => number_format(($sampleTxn->amount + 50000) / 100, 2, '.', ''), // ₦500 difference
                'fee' => number_format($sampleTxn->fee_amount / 100, 2, '.', ''),
                'currency' => $sampleTxn->currency,
                'status' => 'SUCCESS',
                'paid_at' => now()->format('Y-m-d H:i:s'),
                'channel' => 'CARD',
            ];
        }

        // 3. Missing in internal scenario (settled on provider, never captured internally)
        $rows[] = [
            'provider_reference' => 'EXT-SETTLE-' . Str::random(10),
            'transaction_reference' => 'TXN-EXTERNAL-GHOST-01',
            'amount' => '15000.00',
            'fee' => '225.00',
            'currency' => 'NGN',
            'status' => 'SUCCESS',
            'paid_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
            'channel' => 'BANK_TRANSFER',
        ];

        if (strtolower($format) === 'json') {
            return json_encode([
                'provider' => 'SIMULATED_GATEWAY',
                'export_date' => now()->toDateString(),
                'records' => $rows,
            ], JSON_PRETTY_PRINT);
        }

        // CSV formatting
        $csv = "provider_reference,transaction_reference,amount,fee,currency,status,paid_at,channel\n";
        foreach ($rows as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r['provider_reference'],
                $r['transaction_reference'],
                $r['amount'],
                $r['fee'],
                $r['currency'],
                $r['status'],
                $r['paid_at'],
                $r['channel']
            );
        }

        return $csv;
    }
}
