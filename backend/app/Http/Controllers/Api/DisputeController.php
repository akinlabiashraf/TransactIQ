<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function __construct(
        protected DisputeService $disputeService
    ) {}

    /**
     * List all disputes for the merchant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $query = Dispute::where('merchant_id', $merchant->id)
            ->with(['transaction:id,reference,status,amount,currency'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->input('status')));
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $disputes = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $disputes->items(),
            'meta' => [
                'current_page' => $disputes->currentPage(),
                'last_page' => $disputes->lastPage(),
                'per_page' => $disputes->perPage(),
                'total' => $disputes->total(),
            ],
        ]);
    }

    /**
     * Retrieve single dispute details.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $dispute = Dispute::where('merchant_id', $merchant->id)
            ->where('reference', $reference)
            ->with(['transaction'])
            ->first();

        if (!$dispute) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Dispute [{$reference}] not found.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $dispute,
        ]);
    }

    /**
     * Open a dispute on a payment transaction.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $request->validate([
            'transaction_reference' => ['required', 'string'],
            'amount' => ['nullable', 'integer', 'min:100'],
            'reason' => ['nullable', 'string', 'in:CHARGEBACK_FRAUD,UNRECOGNIZED,PRODUCT_NOT_RECEIVED,SERVICE_DEFECTIVE'],
            'metadata' => ['nullable', 'array'],
        ]);

        $transaction = Transaction::where('reference', $request->input('transaction_reference'))
            ->where('merchant_id', $merchant->id)
            ->first();

        if (!$transaction) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Transaction not found for this merchant.",
            ], 404);
        }

        $amount = $request->input('amount') ? (int) $request->input('amount') : null;
        $reason = $request->input('reason') ?? Dispute::REASON_CHARGEBACK_FRAUD;

        $dispute = $this->disputeService->createDispute($transaction, $amount, $reason, $request->input('metadata'));

        return response()->json([
            'status' => 'success',
            'message' => 'Dispute opened and funds held in escrow reserve.',
            'data' => $dispute,
        ], 201);
    }

    /**
     * Submit merchant counter-evidence for dispute defense.
     */
    public function submitEvidence(Request $request, string $reference): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $request->validate([
            'evidence' => ['required', 'array'],
        ]);

        $dispute = Dispute::where('merchant_id', $merchant->id)
            ->where('reference', $reference)
            ->first();

        if (!$dispute) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Dispute [{$reference}] not found.",
            ], 404);
        }

        $updated = $this->disputeService->submitEvidence($dispute, $request->input('evidence'));

        return response()->json([
            'status' => 'success',
            'message' => 'Counter-evidence submitted successfully. Dispute is now under review.',
            'data' => $updated,
        ]);
    }

    /**
     * Adjudicate dispute resolution (WON or LOST).
     */
    public function resolve(Request $request, string $reference): JsonResponse
    {
        $request->validate([
            'outcome' => ['required', 'string', 'in:WON,LOST'],
            'resolution_note' => ['nullable', 'string', 'max:500'],
        ]);

        $dispute = Dispute::where('reference', $reference)->first();

        if (!$dispute) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Dispute [{$reference}] not found.",
            ], 404);
        }

        $resolved = $this->disputeService->resolveDispute(
            $dispute,
            $request->input('outcome'),
            $request->input('resolution_note')
        );

        return response()->json([
            'status' => 'success',
            'message' => "Dispute resolved with outcome [{$request->input('outcome')}].",
            'data' => $resolved,
        ]);
    }
}
