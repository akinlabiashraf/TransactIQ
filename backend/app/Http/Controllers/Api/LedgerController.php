<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Services\Ledger\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function __construct(
        protected LedgerService $ledgerService
    ) {}

    /**
     * List all chart of accounts and real-time balances for merchant and clearing.
     */
    public function accounts(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $currency = strtoupper($request->query('currency', $merchant->default_currency ?? 'NGN'));

        // Ensure foundational chart of accounts exists
        $this->ledgerService->resolveAccounts($merchant, $currency);

        $accounts = LedgerAccount::where(function ($q) use ($merchant) {
            $q->where('merchant_id', $merchant->id)
              ->orWhereNull('merchant_id');
        })
        ->where('currency', $currency)
        ->orderByRaw("CASE 
            WHEN classification = 'MERCHANT_AVAILABLE' THEN 1 
            WHEN classification = 'MERCHANT_PENDING' THEN 2 
            WHEN classification = 'PROVIDER_CLEARING' THEN 3 
            ELSE 4 END")
        ->get();

        return response()->json([
            'status' => 'success',
            'data' => $accounts->map(fn($a) => [
                'id' => $a->id,
                'account_number' => $a->account_number,
                'name' => $a->name,
                'type' => $a->type,
                'classification' => $a->classification,
                'currency' => $a->currency,
                'balance' => $a->balance,
                'owner' => $a->merchant_id ? 'MERCHANT' : 'PLATFORM',
                'status' => $a->status,
            ]),
        ]);
    }

    /**
     * Retrieve chronological immutable ledger journal entries.
     */
    public function entries(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $query = LedgerEntry::with(['debitAccount', 'creditAccount', 'transaction'])
            ->where(function ($q) use ($merchant) {
                $q->whereHas('debitAccount', fn($sub) => $sub->where('merchant_id', $merchant->id))
                  ->orWhereHas('creditAccount', fn($sub) => $sub->where('merchant_id', $merchant->id));
            });

        if ($request->filled('entry_type')) {
            $query->where('entry_type', strtoupper($request->query('entry_type')));
        }

        $entries = $query->orderBy('created_at', 'desc')->limit(50)->get();

        return response()->json([
            'status' => 'success',
            'data' => $entries->map(fn($e) => [
                'id' => $e->id,
                'reference' => $e->reference,
                'entry_type' => $e->entry_type,
                'amount' => $e->amount,
                'currency' => $e->currency,
                'description' => $e->description,
                'debit_account' => $e->debitAccount ? [
                    'account_number' => $e->debitAccount->account_number,
                    'name' => $e->debitAccount->name,
                    'type' => $e->debitAccount->type,
                ] : null,
                'credit_account' => $e->creditAccount ? [
                    'account_number' => $e->creditAccount->account_number,
                    'name' => $e->creditAccount->name,
                    'type' => $e->creditAccount->type,
                ] : null,
                'transaction_reference' => $e->transaction?->reference,
                'created_at' => $e->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Verify mathematical accounting invariant: SUM(Debits) == SUM(Credits).
     */
    public function integrity(Request $request): JsonResponse
    {
        $currency = $request->query('currency', 'NGN');
        $check = $this->ledgerService->verifyLedgerIntegrity($currency);

        return response()->json([
            'status' => 'success',
            'data' => $check,
        ]);
    }
}
