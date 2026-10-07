<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';

    public const REASON_CUSTOMER_REQUEST = 'CUSTOMER_REQUEST';
    public const REASON_DUPLICATE_CHARGE = 'DUPLICATE_CHARGE';
    public const REASON_FRAUDULENT = 'FRAUDULENT';
    public const REASON_ORDER_CANCELLED = 'ORDER_CANCELLED';

    protected $fillable = [
        'reference',
        'transaction_id',
        'merchant_id',
        'amount',
        'currency',
        'status',
        'reason',
        'gateway_refund_reference',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
