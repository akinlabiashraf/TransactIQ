<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'merchant_id',
        'name',
        'type',
        'public_key',
        'secret_key_hash',
        'secret_key_preview',
        'permissions',
        'ip_whitelist',
        'last_used_at',
        'expires_at',
        'status',
    ];

    protected $hidden = [
        'secret_key_hash',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'ip_whitelist' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Generate a new API key pair returning the plaintext secret key once.
     *
     * @return array{public_key: string, secret_key: string, model: ApiKey}
     */
    public static function createKeyPair(Merchant $merchant, string $name = 'Default Key', string $type = 'LIVE', array $permissions = ['payments:read', 'payments:write']): array
    {
        $prefix = strtolower($type) === 'live' ? 'live' : 'test';
        $publicKey = "tiq_{$prefix}_pub_" . Str::random(24);
        $secretKey = "tiq_{$prefix}_sec_" . Str::random(40);
        $preview = "tiq_..." . substr($secretKey, -6);

        $model = static::create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'type' => strtoupper($type),
            'public_key' => $publicKey,
            'secret_key_hash' => hash('sha256', $secretKey),
            'secret_key_preview' => $preview,
            'permissions' => $permissions,
            'status' => 'ACTIVE',
        ]);

        return [
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
            'model' => $model,
        ];
    }

    /**
     * Validate a secret key against the stored SHA256 hash.
     */
    public function verifySecretKey(string $secretKey): bool
    {
        return hash_equals($this->secret_key_hash, hash('sha256', $secretKey));
    }
}
