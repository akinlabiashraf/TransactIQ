<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\WebhookDelivery;
use App\Services\Webhook\WebhookDispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        protected WebhookDispatcherService $dispatcher
    ) {}

    /**
     * List all webhook deliveries for the authenticated merchant with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $query = WebhookDelivery::where('merchant_id', $merchant->id);

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->query('event_type'));
        }

        $deliveries = $query->orderBy('created_at', 'desc')->limit(50)->get();

        return response()->json([
            'status' => 'success',
            'data' => $deliveries->map(fn($d) => $this->transformDelivery($d)),
        ]);
    }

    /**
     * Retrieve single webhook delivery details including raw payload and response.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $delivery = WebhookDelivery::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        if (!$delivery) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Webhook delivery with ID [{$id}] not found.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->transformDelivery($delivery, true),
        ]);
    }

    /**
     * Manually replay a webhook delivery.
     */
    public function replay(Request $request, string $id): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        $delivery = WebhookDelivery::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        if (!$delivery) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Webhook delivery with ID [{$id}] not found.",
            ], 404);
        }

        $replayed = $this->dispatcher->replay($delivery);

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook delivery has been queued for immediate replay.',
            'data' => $this->transformDelivery($replayed, true),
        ]);
    }

    /**
     * Built-in loopback test endpoint for local webhook verification.
     */
    public function testEndpoint(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'received',
            'timestamp' => now()->toIso8601String(),
            'received_signature' => $request->header('X-TransactIQ-Signature'),
            'received_event' => $request->input('event_type'),
        ], 200);
    }

    /**
     * Transform a delivery record for API response.
     */
    protected function transformDelivery(WebhookDelivery $d, bool $includeFull = false): array
    {
        $data = [
            'id' => $d->id,
            'merchant_id' => $d->merchant_id,
            'transaction_id' => $d->transaction_id,
            'event_type' => $d->event_type,
            'endpoint_url' => $d->endpoint_url,
            'signature' => $d->signature,
            'attempts' => $d->attempts,
            'max_attempts' => $d->max_attempts,
            'status' => $d->status,
            'response_status' => $d->response_status,
            'next_retry_at' => $d->next_retry_at?->toIso8601String(),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'created_at' => $d->created_at->toIso8601String(),
        ];

        if ($includeFull) {
            $data['payload'] = $d->payload;
            $data['response_body'] = $d->response_body;
        }

        return $data;
    }
}
