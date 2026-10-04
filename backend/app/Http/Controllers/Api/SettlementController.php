<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Services\Settlement\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettlementController extends Controller
{
    public function __construct(
        protected SettlementService $settlementService
    ) {}

    /**
     * List all settlement batches for the merchant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $query = Settlement::where('merchant_id', $merchant->id);

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }

        $settlements = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $settlements->map(fn($s) => $this->transformSettlement($s)),
        ]);
    }

    /**
     * View detailed settlement batch with transactions.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $settlement = Settlement::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->with('transactions')
            ->first();

        if (!$settlement) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Settlement batch [{$id}] not found.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->transformSettlement($settlement, true),
        ]);
    }

    /**
     * Generate a new settlement batch from unsettled cleared transactions.
     */
    public function generate(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $currency = $request->input('currency', $merchant->default_currency ?? 'NGN');

        $settlement = $this->settlementService->generateSettlementBatch($merchant, $currency);

        if (!$settlement) {
            return response()->json([
                'status' => 'noop',
                'message' => 'No eligible cleared transactions found awaiting settlement.',
                'data' => null,
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Settlement batch [{$settlement->settlement_reference}] created successfully.",
            'data' => $this->transformSettlement($settlement, true),
        ], 201);
    }

    /**
     * Execute payout fulfillment for a pending settlement batch.
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $settlement = Settlement::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        if (!$settlement) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Settlement batch [{$id}] not found.",
            ], 404);
        }

        $completed = $this->settlementService->completeSettlementPayout(
            $settlement,
            $request->input('payout_reference')
        );

        return response()->json([
            'status' => 'success',
            'message' => "Settlement batch [{$completed->settlement_reference}] marked as COMPLETED.",
            'data' => $this->transformSettlement($completed, true),
        ]);
    }

    protected function transformSettlement(Settlement $s, bool $includeTxns = false): array
    {
        $data = [
            'id' => $s->id,
            'settlement_reference' => $s->settlement_reference,
            'merchant_id' => $s->merchant_id,
            'gross_amount' => $s->gross_amount,
            'fee_amount' => $s->fee_amount,
            'net_amount' => $s->net_amount,
            'currency' => $s->currency,
            'status' => $s->status,
            'transaction_count' => $s->transaction_count,
            'payout_channel' => $s->payout_channel,
            'payout_reference' => $s->payout_reference,
            'payout_date' => $s->payout_date?->toIso8601String(),
            'created_at' => $s->created_at->toIso8601String(),
        ];

        if ($includeTxns && $s->relationLoaded('transactions')) {
            $data['transactions'] = $s->transactions->map(fn($t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'amount' => $t->amount,
                'net_amount' => $t->net_amount,
                'status' => $t->status,
                'paid_at' => $t->paid_at?->toIso8601String(),
            ])->toArray();
        }

        return $data;
    }
}
