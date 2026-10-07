<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Refund;
use App\Models\Transaction;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(
        protected RefundService $refundService
    ) {}

    /**
     * Process a full or partial refund for a specific payment transaction.
     */
    public function store(Request $request, string $reference): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $request->validate([
            'amount' => ['nullable', 'integer', 'min:100'],
            'reason' => ['nullable', 'string', 'in:CUSTOMER_REQUEST,DUPLICATE_CHARGE,FRAUDULENT,ORDER_CANCELLED'],
            'metadata' => ['nullable', 'array'],
        ]);

        $transaction = Transaction::where('reference', $reference)
            ->where('merchant_id', $merchant->id)
            ->first();

        if (!$transaction) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Transaction [{$reference}] not found for this merchant.",
            ], 404);
        }

        $amount = $request->input('amount') ? (int) $request->input('amount') : null;
        $reason = $request->input('reason') ?? Refund::REASON_CUSTOMER_REQUEST;
        $metadata = $request->input('metadata');

        $refund = $this->refundService->processRefund($transaction, $amount, $reason, $metadata);

        return response()->json([
            'status' => 'success',
            'message' => 'Refund processed successfully.',
            'data' => [
                'id' => $refund->id,
                'reference' => $refund->reference,
                'transaction_reference' => $transaction->reference,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'status' => $refund->status,
                'reason' => $refund->reason,
                'created_at' => $refund->created_at->toIso8601String(),
                'transaction_new_status' => $transaction->fresh()->status,
                'remaining_refundable' => $transaction->fresh()->refundableAmount(),
            ],
        ], 201);
    }

    /**
     * List all refunds for the authenticated merchant with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $query = Refund::where('merchant_id', $merchant->id)
            ->with(['transaction:id,reference,status,amount,currency'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->input('status')));
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $refunds = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $refunds->items(),
            'meta' => [
                'current_page' => $refunds->currentPage(),
                'last_page' => $refunds->lastPage(),
                'per_page' => $refunds->perPage(),
                'total' => $refunds->total(),
            ],
        ]);
    }

    /**
     * Retrieve single refund details.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $refund = Refund::where('merchant_id', $merchant->id)
            ->where('reference', $reference)
            ->with(['transaction'])
            ->first();

        if (!$refund) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Refund [{$reference}] not found.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $refund,
        ]);
    }
}
