<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(
        protected LedgerService $ledgerService
    ) {}

    /**
     * Compute aggregated operational financial metrics, ledger health, and volume statistics.
     */
    public function summary(Request $request): JsonResponse
    {
        /** @var Merchant|null $merchant */
        $merchant = $request->attributes->get('merchant');

        $txnQuery = Transaction::query();
        $settlementQuery = Settlement::query();

        if ($merchant) {
            $txnQuery->where('merchant_id', $merchant->id);
            $settlementQuery->where('merchant_id', $merchant->id);
        }

        // Aggregate volume metrics
        $totalVolume = (int) (clone $txnQuery)->where('status', Transaction::STATUS_SUCCESS)->sum('amount');
        $totalFees = (int) (clone $txnQuery)->where('status', Transaction::STATUS_SUCCESS)->sum('fee_amount');
        $totalNet = (int) (clone $txnQuery)->where('status', Transaction::STATUS_SUCCESS)->sum('net_amount');

        // Aggregate count metrics
        $totalCount = (clone $txnQuery)->count();
        $successCount = (clone $txnQuery)->where('status', Transaction::STATUS_SUCCESS)->count();
        $failedCount = (clone $txnQuery)->where('status', Transaction::STATUS_FAILED)->count();
        $pendingCount = (clone $txnQuery)->whereIn('status', [Transaction::STATUS_PENDING, Transaction::STATUS_PROCESSING])->count();

        $successRate = $totalCount > 0 ? round(($successCount / $totalCount) * 100, 1) : 100.0;

        // Settlement metrics
        $pendingSettlementVolume = (int) (clone $settlementQuery)
            ->whereIn('status', [Settlement::STATUS_PENDING, Settlement::STATUS_PROCESSING])
            ->sum('net_amount');
        $completedSettlementCount = (clone $settlementQuery)
            ->where('status', Settlement::STATUS_COMPLETED)
            ->count();

        // Ledger mathematical integrity verification
        $ledgerAudit = $this->ledgerService->verifyLedgerIntegrity();

        // Recent 5 transactions
        $recentTransactions = (clone $txnQuery)
            ->with('customer')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'reference' => $t->reference,
                    'amount' => $t->amount,
                    'fee_amount' => $t->fee_amount,
                    'net_amount' => $t->net_amount,
                    'currency' => $t->currency,
                    'status' => $t->status,
                    'payment_method' => $t->payment_method,
                    'customer_email' => $t->customer?->email ?? 'Guest Customer',
                    'created_at' => $t->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'overview' => [
                    'cleared_volume' => $totalVolume,
                    'fee_revenue' => $totalFees,
                    'net_payout_volume' => $totalNet,
                    'currency' => 'NGN',
                    'total_transactions' => $totalCount,
                    'successful_transactions' => $successCount,
                    'failed_transactions' => $failedCount,
                    'pending_transactions' => $pendingCount,
                    'success_rate' => $successRate,
                ],
                'settlements' => [
                    'pending_volume' => $pendingSettlementVolume,
                    'completed_batches' => $completedSettlementCount,
                ],
                'ledger_health' => [
                    'is_balanced' => $ledgerAudit['is_balanced'],
                    'total_debits' => $ledgerAudit['total_debits'],
                    'total_credits' => $ledgerAudit['total_credits'],
                    'net_variance' => $ledgerAudit['discrepancy'],
                    'total_entries' => $ledgerAudit['total_entries'],
                ],
                'recent_transactions' => $recentTransactions,
            ],
        ]);
    }
}
