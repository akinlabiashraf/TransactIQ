<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    use HasUuids;

    protected $fillable = [
        'merchant_code',
        'name',
        'business_email',
        'business_phone',
        'country',
        'default_currency',
        'status',
        'webhook_url',
        'webhook_secret',
        'fee_basis_points',
        'fee_flat_minor',
        'settlement_bank_details',
    ];

    protected function casts(): array
    {
        return [
            'settlement_bank_details' => 'array',
            'fee_basis_points' => 'integer',
            'fee_flat_minor' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function ledgerAccounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
