<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhook\WebhookDispatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the queued job may be attempted.
     */
    public int $tries = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $deliveryId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WebhookDispatcherService $dispatcher): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);
        if (!$delivery) {
            Log::warning("DispatchWebhookJob: Delivery [{$this->deliveryId}] not found.");
            return;
        }

        // Do not re-deliver already successful deliveries
        if ($delivery->status === WebhookDelivery::STATUS_DELIVERED) {
            return;
        }

        $success = $dispatcher->deliver($delivery);

        // If delivery failed and is eligible for retry, schedule delayed release
        if (!$success && $delivery->status === WebhookDelivery::STATUS_RETRYING && $delivery->next_retry_at) {
            $delay = max(5, $delivery->next_retry_at->diffInSeconds(now()));
            $this->release($delay);
        }
    }
}
