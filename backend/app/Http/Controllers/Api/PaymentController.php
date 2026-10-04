<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentRequest;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Services\IdempotencyService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected IdempotencyService $idempotencyService,
        protected TransactionService $transactionService
    ) {}

    /**
     * Initiate and process a new payment transaction with idempotency enforcement.
     */
    public function store(CreatePaymentRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        // 1. Validate Idempotency Key header
        $idempotencyKey = $this->idempotencyService->validateKey($request->header('Idempotency-Key'));

        // 2. Check for existing idempotent transaction replay
        $existing = $this->idempotencyService->findExisting($merchant, $idempotencyKey);
        if ($existing) {
            $this->idempotencyService->verifyPayloadMatch($existing->request_hash, $request->validated(), $idempotencyKey);

            return response()->json([
                'status' => 'success',
                'message' => 'Idempotent request replayed. Returning existing transaction.',
                'data' => $this->transformTransaction($existing),
            ], 200)->header('X-Idempotent-Replay', 'true');
        }

        // 3. Acquire Distributed Concurrency Lock
        $lock = $this->idempotencyService->acquireLock($merchant, $idempotencyKey);

        try {
            // Re-check inside lock to protect against race conditions
            $existing = $this->idempotencyService->findExisting($merchant, $idempotencyKey);
            if ($existing) {
                $this->idempotencyService->verifyPayloadMatch($existing->request_hash, $request->validated(), $idempotencyKey);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Idempotent request replayed. Returning existing transaction.',
                    'data' => $this->transformTransaction($existing),
                ], 200)->header('X-Idempotent-Replay', 'true');
            }

            // 4. Create and execute payment through the State Machine
            $transaction = $this->transactionService->createAndProcessPayment(
                $merchant,
                $request->validated(),
                $idempotencyKey
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Payment initiated and processed successfully.',
                'data' => $this->transformTransaction($transaction),
            ], 201);
        } finally {
            $this->idempotencyService->releaseLock($lock);
        }
    }

    /**
     * Retrieve transaction details and full state machine audit timeline.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $transaction = Transaction::where('merchant_id', $merchant->id)
            ->where('reference', $reference)
            ->with(['customer', 'events', 'paymentAttempts'])
            ->first();

        if (!$transaction) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Transaction with reference [{$reference}] not found.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->transformTransaction($transaction),
        ]);
    }

    /**
     * List all transactions for the authenticated merchant with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $query = Transaction::where('merchant_id', $merchant->id)
            ->with(['customer'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', strtoupper($request->query('payment_method')));
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $transactions = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => array_map([$this, 'transformTransactionSummary'], $transactions->items()),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ],
        ]);
    }

    /**
     * Format a complete transaction model with event timeline and attempts.
     */
    protected function transformTransaction(Transaction $t): array
    {
        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'merchant_id' => $t->merchant_id,
            'amount' => $t->amount,
            'fee_amount' => $t->fee_amount,
            'net_amount' => $t->net_amount,
            'currency' => $t->currency,
            'status' => $t->status,
            'payment_method' => $t->payment_method,
            'idempotency_key' => $t->idempotency_key,
            'provider' => $t->provider,
            'provider_reference' => $t->provider_reference,
            'failure_reason' => $t->failure_reason,
            'paid_at' => $t->paid_at?->toIso8601String(),
            'created_at' => $t->created_at->toIso8601String(),
            'customer' => $t->customer ? [
                'id' => $t->customer->id,
                'customer_code' => $t->customer->customer_code,
                'email' => $t->customer->email,
                'name' => $t->customer->name,
                'phone' => $t->customer->phone,
            ] : null,
            'events' => $t->events->map(fn($e) => [
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'event_type' => $e->event_type,
                'triggered_by' => $e->triggered_by,
                'payload' => $e->payload,
                'created_at' => $e->created_at->toIso8601String(),
            ])->toArray(),
            'payment_attempts' => $t->paymentAttempts->map(fn($a) => [
                'attempt_number' => $a->attempt_number,
                'provider' => $a->provider,
                'provider_reference' => $a->provider_reference,
                'status' => $a->status,
                'error_code' => $a->error_code,
                'error_message' => $a->error_message,
                'gateway_response' => $a->gateway_response,
                'latency_ms' => $a->latency_ms,
                'created_at' => $a->created_at->toIso8601String(),
            ])->toArray(),
        ];
    }

    /**
     * Compact summary for list queries.
     */
    protected function transformTransactionSummary(Transaction $t): array
    {
        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'amount' => $t->amount,
            'fee_amount' => $t->fee_amount,
            'net_amount' => $t->net_amount,
            'currency' => $t->currency,
            'status' => $t->status,
            'payment_method' => $t->payment_method,
            'idempotency_key' => $t->idempotency_key,
            'paid_at' => $t->paid_at?->toIso8601String(),
            'created_at' => $t->created_at->toIso8601String(),
            'customer' => $t->customer ? [
                'email' => $t->customer->email,
                'name' => $t->customer->name,
            ] : null,
        ];
    }
}
