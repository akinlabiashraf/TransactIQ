<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    use HasUuids;

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_UNDER_REVIEW = 'UNDER_REVIEW';
    public const STATUS_WON = 'WON';
    public const STATUS_LOST = 'LOST';

    public const REASON_CHARGEBACK_FRAUD = 'CHARGEBACK_FRAUD';
    public const REASON_UNRECOGNIZED = 'UNRECOGNIZED';
    public const REASON_PRODUCT_NOT_RECEIVED = 'PRODUCT_NOT_RECEIVED';
    public const REASON_SERVICE_DEFECTIVE = 'SERVICE_DEFECTIVE';

    protected $fillable = [
        'reference',
        'transaction_id',
        'merchant_id',
        'amount',
        'currency',
        'status',
        'reason',
        'evidence',
        'due_at',
        'resolved_at',
        'resolution_note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'evidence' => 'array',
            'metadata' => 'array',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
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
