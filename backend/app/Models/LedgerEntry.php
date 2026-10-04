<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    public const TYPE_PAYMENT_CAPTURED = 'PAYMENT_CAPTURED';
    public const TYPE_PLATFORM_FEE = 'PLATFORM_FEE';
    public const TYPE_SETTLEMENT_PAYOUT = 'SETTLEMENT_PAYOUT';
    public const TYPE_REVERSAL = 'REVERSAL';

    protected $fillable = [
        'transaction_id',
        'debit_account_id',
        'credit_account_id',
        'amount',
        'currency',
        'entry_type',
        'reference',
        'description',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'credit_account_id');
    }
}
