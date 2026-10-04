<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class IdempotencyService
{
    /**
     * Validate the syntax and presence of an idempotency key.
     */
    public function validateKey(?string $key): string
    {
        if (empty($key)) {
            throw new UnprocessableEntityHttpException(
                'Missing required header [Idempotency-Key]. TransactIQ requires an idempotency key to prevent accidental duplicate charges.'
            );
        }

        $trimmed = trim($key);
        if (strlen($trimmed) < 8 || strlen($trimmed) > 128) {
            throw new UnprocessableEntityHttpException(
                'The [Idempotency-Key] must be between 8 and 128 characters in length.'
            );
        }

        return $trimmed;
    }

    /**
     * Look up an existing transaction created with this idempotency key.
     */
    public function findExisting(Merchant $merchant, string $key): ?Transaction
    {
        return Transaction::where('merchant_id', $merchant->id)
            ->where('idempotency_key', $key)
            ->with(['customer', 'events'])
            ->first();
    }

    /**
     * Acquire a distributed Redis lock to prevent sub-millisecond race conditions.
     *
     * @throws ConflictHttpException if an identical request is actively processing.
     */
    public function acquireLock(Merchant $merchant, string $key, int $ttlSeconds = 60): Lock
    {
        $lockKey = "idemp_lock:{$merchant->id}:{$key}";
        $lock = Cache::lock($lockKey, $ttlSeconds);

        // Attempt to acquire lock immediately (no wait to fail fast on concurrent race)
        if (!$lock->get()) {
            throw new ConflictHttpException(
                'A concurrent transaction with the identical Idempotency-Key is currently processing. Please wait for the initial request to complete.'
            );
        }

        return $lock;
    }

    /**
     * Safely release the lock.
     */
    public function releaseLock(?Lock $lock): void
    {
        if ($lock) {
            $lock->release();
        }
    }

    /**
     * Compute a deterministic cryptographic SHA-256 checksum of the request payload.
     * Sanitizes sensitive transient fields (e.g. CVV) and canonically sorts keys.
     */
    public function computePayloadHash(array $payload): string
    {
        $canonical = $this->canonicalizePayload($payload);
        return hash('sha256', json_encode($canonical));
    }

    /**
     * Recursively sort keys and remove non-idempotent or PCI-sensitive fields (CVV).
     */
    protected function canonicalizePayload(array $data): array
    {
        unset($data['cvv'], $data['card']['cvv']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonicalizePayload($value);
            }
        }

        ksort($data);
        return $data;
    }

    /**
     * Verify that incoming payload matches the stored transaction request hash.
     *
     * @throws UnprocessableEntityHttpException if payload differs.
     */
    public function verifyPayloadMatch(?string $storedHash, array $incomingPayload, string $key): void
    {
        if (empty($storedHash)) {
            return;
        }

        $incomingHash = $this->computePayloadHash($incomingPayload);
        if ($storedHash !== $incomingHash) {
            throw new UnprocessableEntityHttpException(
                "Idempotency key [{$key}] was already used with different transaction parameters. TransactIQ prohibits altering payment parameters on an existing idempotency key."
            );
        }
    }
}
