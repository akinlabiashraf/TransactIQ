<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationException extends Model
{
    use HasUuids;

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_INVESTIGATING = 'INVESTIGATING';
    public const STATUS_RESOLVED = 'RESOLVED';
    public const STATUS_WRITTEN_OFF = 'WRITTEN_OFF';

    public const TYPE_MISSING_IN_INTERNAL = 'MISSING_IN_INTERNAL';
    public const TYPE_MISSING_IN_PROVIDER = 'MISSING_IN_PROVIDER';
    public const TYPE_AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';
    public const TYPE_STATUS_MISMATCH = 'STATUS_MISMATCH';

    protected $fillable = [
        'reconciliation_run_id',
        'transaction_id',
        'internal_reference',
        'provider_reference',
        'exception_type',
        'internal_amount_minor',
        'provider_amount_minor',
        'internal_status',
        'provider_status',
        'discrepancy_details',
        'status',
        'resolved_by_user_id',
        'resolution_notes',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'internal_amount_minor' => 'integer',
            'provider_amount_minor' => 'integer',
            'discrepancy_details' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
