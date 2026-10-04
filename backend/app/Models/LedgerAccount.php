<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    use HasUuids;

    // Account Types
    public const TYPE_ASSET = 'ASSET';
    public const TYPE_LIABILITY = 'LIABILITY';
    public const TYPE_EQUITY = 'EQUITY';
    public const TYPE_REVENUE = 'REVENUE';
    public const TYPE_EXPENSE = 'EXPENSE';

    // Core Classifications
    public const CLASS_MERCHANT_AVAILABLE = 'MERCHANT_AVAILABLE';
    public const CLASS_MERCHANT_PENDING = 'MERCHANT_PENDING';
    public const CLASS_PLATFORM_REVENUE = 'PLATFORM_FEE_REVENUE';
    public const CLASS_PROVIDER_CLEARING = 'PROVIDER_CLEARING';
    public const CLASS_ESCROW = 'ESCROW';

    protected $fillable = [
        'merchant_id',
        'account_number',
        'name',
        'type',
        'classification',
        'currency',
        'balance',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function debitEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'debit_account_id');
    }

    public function creditEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'credit_account_id');
    }
}
