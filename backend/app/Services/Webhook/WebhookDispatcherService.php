<?php

namespace App\Services\Webhook;

use App\Jobs\DispatchWebhookJob;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhookDispatcherService
{
    public function __construct(
        protected WebhookSignerService $signer
    ) {}

    /**
     * Dispatch an event webhook for a completed or updated transaction.
     */
    public function dispatchTransactionWebhook(Transaction $transaction, ?string $customEventType = null): WebhookDelivery
    {
        /** @var Merchant $merchant */
        $merchant = $transaction->merchant ?? Merchant::findOrFail($transaction->merchant_id);

        $eventType = $customEventType ?? match ($transaction->status) {
            Transaction::STATUS_SUCCESS => 'payment.success',
            Transaction::STATUS_FAILED => 'payment.failed',
            default => 'transaction.updated',
        };

        $endpointUrl = $merchant->webhook_url ?: 'https://webhook.site/test-transactiq';
        $secret = $merchant->webhook_secret ?: 'whsec_' . md5($merchant->id);

        $eventId = 'evt_' . (string) Str::uuid();
        $payload = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'created_at' => now()->toIso8601String(),
            'data' => [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'merchant_id' => $merchant->id,
                'merchant_name' => $merchant->name,
                'amount' => $transaction->amount,
                'fee_amount' => $transaction->fee_amount,
                'net_amount' => $transaction->net_amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status,
                'payment_method' => $transaction->payment_method,
                'failure_reason' => $transaction->failure_reason,
                'provider' => $transaction->provider,
                'provider_reference' => $transaction->provider_reference,
                'paid_at' => $transaction->paid_at?->toIso8601String(),
                'customer' => $transaction->customer ? [
                    'id' => $transaction->customer->id,
                    'customer_code' => $transaction->customer->customer_code,
                    'email' => $transaction->customer->email,
                    'name' => $transaction->customer->name,
                ] : null,
                'metadata' => $transaction->metadata,
            ],
        ];

        $sigInfo = $this->signer->generateSignature($payload, $secret);

        $delivery = WebhookDelivery::create([
            'merchant_id' => $merchant->id,
            'transaction_id' => $transaction->id,
            'event_type' => $eventType,
            'endpoint_url' => $endpointUrl,
            'signature' => $sigInfo['header'],
            'payload' => $payload,
            'attempts' => 0,
            'max_attempts' => 5,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        // Dispatch background delivery job
        DispatchWebhookJob::dispatch($delivery->id);

        return $delivery;
    }

    /**
     * Execute HTTP delivery for a specific webhook delivery record.
     */
    public function deliver(WebhookDelivery $delivery): bool
    {
        $delivery->attempts++;

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'TransactIQ-Webhook-Engine/1.0',
                    'X-TransactIQ-Signature' => $delivery->signature,
                    'X-TransactIQ-Event-Id' => $delivery->payload['event_id'] ?? $delivery->id,
                    'X-TransactIQ-Delivery-Attempt' => (string) $delivery->attempts,
                ])
                ->post($delivery->endpoint_url, $delivery->payload);

            $statusCode = $response->status();
            $responseBody = Str::limit($response->body(), 2000);

            if ($response->successful()) {
                $delivery->update([
                    'status' => WebhookDelivery::STATUS_DELIVERED,
                    'response_status' => $statusCode,
                    'response_body' => $responseBody,
                    'delivered_at' => now(),
                    'next_retry_at' => null,
                ]);
                return true;
            }

            // HTTP 4xx / 5xx error
            $this->handleFailure($delivery, $statusCode, $responseBody);
            return false;
        } catch (\Throwable $e) {
            // Connection timeout, DNS failure, or network drop
            $this->handleFailure($delivery, 504, 'Connection failure: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Compute exponential backoff retry schedule or transition to terminal failure.
     */
    protected function handleFailure(WebhookDelivery $delivery, int $status, string $message): void
    {
        if ($delivery->attempts >= $delivery->max_attempts) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'response_status' => $status,
                'response_body' => $message,
                'next_retry_at' => null,
            ]);
            return;
        }

        $backoffSeconds = $this->calculateBackoff($delivery->attempts);
        $nextRetry = now()->addSeconds($backoffSeconds);

        $delivery->update([
            'status' => WebhookDelivery::STATUS_RETRYING,
            'response_status' => $status,
            'response_body' => $message,
            'next_retry_at' => $nextRetry,
        ]);
    }

    /**
     * Calculate jittered exponential backoff intervals in seconds.
     * Attempt 1: 60s (1m)
     * Attempt 2: 300s (5m)
     * Attempt 3: 1800s (30m)
     * Attempt 4: 7200s (2h)
     */
    public function calculateBackoff(int $attempt): int
    {
        return match ($attempt) {
            1 => 60,
            2 => 300,
            3 => 1800,
            4 => 7200,
            default => 14400,
        };
    }

    /**
     * Reset and replay a webhook delivery.
     */
    public function replay(WebhookDelivery $delivery): WebhookDelivery
    {
        $delivery->update([
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
            'response_status' => null,
            'response_body' => null,
            'next_retry_at' => null,
            'delivered_at' => null,
        ]);

        DispatchWebhookJob::dispatch($delivery->id);

        return $delivery->fresh();
    }
}
