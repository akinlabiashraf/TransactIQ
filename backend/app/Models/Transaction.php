<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasUuids;

    // Strict Finite State Machine Enums
    public const STATUS_INITIATED = 'INITIATED';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_REVERSED = 'REVERSED';
    public const STATUS_REFUNDED = 'REFUNDED';
    public const STATUS_PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
    public const STATUS_DISPUTED = 'DISPUTED';

    protected $fillable = [
        'reference',
        'merchant_id',
        'customer_id',
        'settlement_id',
        'idempotency_key',
        'request_hash',
        'amount',
        'fee_amount',
        'net_amount',
        'currency',
        'status',
        'payment_method',
        'channel',
        'provider',
        'provider_reference',
        'failure_reason',
        'metadata',
        'paid_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'net_amount' => 'integer',
            'metadata' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransactionEvent::class)->orderBy('created_at', 'asc');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('attempt_number', 'asc');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function reconciliationExceptions(): HasMany
    {
        return $this->hasMany(ReconciliationException::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('created_at', 'asc');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class)->orderBy('created_at', 'asc');
    }

    /**
     * Total amount in minor units already refunded for this transaction.
     */
    public function totalRefundedAmount(): int
    {
        return (int) $this->refunds()
            ->where('status', Refund::STATUS_COMPLETED)
            ->sum('amount');
    }

    /**
     * Remaining amount available to be refunded.
     */
    public function refundableAmount(): int
    {
        return max(0, $this->amount - $this->totalRefundedAmount());
    }

    /**
     * Determine if a transition to a new status is valid per FSM rules.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowedTransitions = [
            self::STATUS_INITIATED => [self::STATUS_PROCESSING, self::STATUS_FAILED],
            self::STATUS_PROCESSING => [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_PENDING],
            self::STATUS_PENDING => [self::STATUS_SUCCESS, self::STATUS_FAILED],
            self::STATUS_SUCCESS => [
                self::STATUS_REVERSED,
                self::STATUS_PARTIALLY_REFUNDED,
                self::STATUS_REFUNDED,
                self::STATUS_DISPUTED,
            ],
            self::STATUS_PARTIALLY_REFUNDED => [
                self::STATUS_PARTIALLY_REFUNDED,
                self::STATUS_REFUNDED,
                self::STATUS_REVERSED,
            ],
            self::STATUS_DISPUTED => [
                self::STATUS_SUCCESS,
                self::STATUS_REVERSED,
                self::STATUS_REFUNDED,
            ],
            self::STATUS_FAILED => [],
            self::STATUS_REVERSED => [],
            self::STATUS_REFUNDED => [],
        ];

        return in_array($newStatus, $allowedTransitions[$this->status] ?? [], true);
    }
}
