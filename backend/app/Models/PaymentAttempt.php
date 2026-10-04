<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'attempt_number',
        'provider',
        'provider_reference',
        'status',
        'error_code',
        'error_message',
        'gateway_request',
        'gateway_response',
        'latency_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'gateway_request' => 'array',
            'gateway_response' => 'array',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
