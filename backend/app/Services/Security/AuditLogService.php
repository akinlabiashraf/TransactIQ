<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AuditLogService
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    protected static ?string $lastRecordedHash = null;

    public static function resetChainCache(): void
    {
        self::$lastRecordedHash = null;
    }

    /**
     * Log an immutable audit record with cryptographic integrity chaining and PCI-DSS data scrubbing.
     */
    public function logMutation(
        string $action,
        string $entityType,
        string $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $actorType = 'SYSTEM',
        ?User $user = null,
        ?Merchant $merchant = null
    ): AuditLog {
        $user = $user ?? auth()->user();
        $merchant = $merchant ?? (request()->attributes->get('merchant') ?? $user?->merchant);
        $userId = $user?->id;
        $merchantId = $merchant?->id;

        // 1. PCI-DSS Compliance scrubbing on old and new states
        $cleanOldValues = $oldValues !== null ? $this->scrubSensitiveData($oldValues) : null;
        $cleanNewValues = $newValues !== null ? $this->scrubSensitiveData($newValues) : [];

        // 2. Determine previous entry hash for chaining
        if (self::$lastRecordedHash !== null) {
            $prevHash = self::$lastRecordedHash;
        } else {
            $latestLog = AuditLog::orderBy('created_at', 'desc')->first();
            $prevHash = $latestLog?->new_values['_integrity']['hash'] ?? self::GENESIS_HASH;
        }

        $createdAt = now();
        $ip = request()->ip() ?? '127.0.0.1';
        $ua = request()->userAgent() ?? 'TransactIQ-System/1.0';

        // 3. Compute SHA-256 integrity signature
        $integrityPayload = [
            'prev_hash' => $prevHash,
            'actor_type' => $actorType,
            'user_id' => $userId,
            'merchant_id' => $merchantId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'old_values' => $cleanOldValues,
            'new_values_payload' => $cleanNewValues,
            'created_at' => $createdAt->toIso8601String(),
        ];

        $signature = hash('sha256', json_encode($integrityPayload, JSON_UNESCAPED_SLASHES));

        // Inject cryptographic integrity envelope
        $cleanNewValues['_integrity'] = [
            'hash' => $signature,
            'prev_hash' => $prevHash,
            'algo' => 'sha256',
            'verified' => true,
        ];

        self::$lastRecordedHash = $signature;

        // 4. Persist immutable record
        return AuditLog::create([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'merchant_id' => $merchantId,
            'actor_type' => $actorType,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'old_values' => $cleanOldValues,
            'new_values' => $cleanNewValues,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Log payment lifecycle mutations.
     */
    public function logPaymentEvent(Transaction $transaction, string $action, ?array $details = null): AuditLog
    {
        $actorType = request()->attributes->has('api_key') ? 'API' : 'SYSTEM';

        return $this->logMutation(
            action: $action,
            entityType: 'Transaction',
            entityId: $transaction->id,
            oldValues: null,
            newValues: array_merge([
                'reference' => $transaction->reference,
                'status' => $transaction->status,
                'amount' => $transaction->amount,
                'fee_amount' => $transaction->fee_amount,
                'currency' => $transaction->currency,
                'provider' => $transaction->provider,
                'provider_reference' => $transaction->provider_reference,
            ], $details ?? []),
            actorType: $actorType,
            user: null,
            merchant: $transaction->merchant
        );
    }

    /**
     * Log settlement lifecycle actions.
     */
    public function logSettlementEvent(Settlement $settlement, string $action, ?array $details = null): AuditLog
    {
        return $this->logMutation(
            action: $action,
            entityType: 'Settlement',
            entityId: $settlement->id,
            oldValues: null,
            newValues: array_merge([
                'settlement_reference' => $settlement->settlement_reference,
                'status' => $settlement->status,
                'gross_amount' => $settlement->gross_amount,
                'net_amount' => $settlement->net_amount,
                'transaction_count' => $settlement->transaction_count,
                'payout_reference' => $settlement->payout_reference,
            ], $details ?? []),
            actorType: 'OPERATIONS',
            user: auth()->user(),
            merchant: $settlement->merchant
        );
    }

    /**
     * Log reconciliation events (run completion, exception resolutions).
     */
    public function logReconciliationEvent(mixed $entity, string $action, ?array $details = null): AuditLog
    {
        $entityType = class_basename($entity);
        $entityId = $entity->id ?? (string) Str::uuid();
        $merchant = $entity->merchant ?? null;

        return $this->logMutation(
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            oldValues: null,
            newValues: $details ?? [],
            actorType: 'OPERATIONS',
            user: auth()->user(),
            merchant: $merchant
        );
    }

    /**
     * Verify the cryptographic hash integrity of a single audit log entry.
     */
    public function verifyRecordIntegrity(AuditLog $log): bool
    {
        $newValues = $log->new_values ?? [];
        $integrity = $newValues['_integrity'] ?? null;

        if (!$integrity || empty($integrity['hash']) || empty($integrity['prev_hash'])) {
            return false;
        }

        $recordedHash = $integrity['hash'];
        $prevHash = $integrity['prev_hash'];

        // Clean new values payload excluding _integrity
        $cleanPayload = $newValues;
        unset($cleanPayload['_integrity']);

        $expectedPayload = [
            'prev_hash' => $prevHash,
            'actor_type' => $log->actor_type,
            'user_id' => $log->user_id,
            'merchant_id' => $log->merchant_id,
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => (string) $log->entity_id,
            'old_values' => $log->old_values,
            'new_values_payload' => $cleanPayload,
            'created_at' => $log->created_at->toIso8601String(),
        ];

        $recomputed = hash('sha256', json_encode($expectedPayload, JSON_UNESCAPED_SLASHES));

        return hash_equals($recordedHash, $recomputed);
    }

    /**
     * Verify the integrity of the audit log chain from oldest to newest.
     */
    public function verifyChainIntegrity(?string $merchantId = null): array
    {
        $query = AuditLog::query();
        if ($merchantId) {
            $query->where('merchant_id', $merchantId);
        }

        $logs = $query->get();
        $total = $logs->count();

        if ($total === 0) {
            return [
                'total_records' => 0,
                'valid_records' => 0,
                'is_chain_healthy' => true,
                'corrupted_records' => [],
                'genesis_hash' => self::GENESIS_HASH,
                'latest_hash' => self::GENESIS_HASH,
            ];
        }

        // Map records by prev_hash for deterministic pointer chain traversal
        $byPrevHash = [];
        foreach ($logs as $log) {
            $prevH = $log->new_values['_integrity']['prev_hash'] ?? null;
            if ($prevH) {
                $byPrevHash[$prevH] = $log;
            }
        }

        $validCount = 0;
        $corrupted = [];
        $expectedPrev = self::GENESIS_HASH;
        $visitedIds = [];
        $latestHash = self::GENESIS_HASH;

        while (isset($byPrevHash[$expectedPrev])) {
            $log = $byPrevHash[$expectedPrev];
            $visitedIds[$log->id] = true;

            $isValid = $this->verifyRecordIntegrity($log);
            if ($isValid) {
                $validCount++;
                $expectedPrev = $log->new_values['_integrity']['hash'];
                $latestHash = $expectedPrev;
            } else {
                $corrupted[] = [
                    'id' => $log->id,
                    'action' => $log->action,
                    'record_valid' => false,
                    'chain_link_valid' => true,
                ];
                break;
            }
        }

        // Check for any detached or orphan records
        foreach ($logs as $log) {
            if (!isset($visitedIds[$log->id])) {
                $corrupted[] = [
                    'id' => $log->id,
                    'action' => $log->action,
                    'record_valid' => $this->verifyRecordIntegrity($log),
                    'chain_link_valid' => false,
                ];
            }
        }

        return [
            'total_records' => $total,
            'valid_records' => $validCount,
            'is_chain_healthy' => $total === $validCount && empty($corrupted),
            'corrupted_records' => $corrupted,
            'genesis_hash' => self::GENESIS_HASH,
            'latest_hash' => $latestHash,
        ];
    }

    /**
     * Query audit logs with pagination and multi-attribute filters.
     */
    public function queryLogs(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return AuditLog::query()
            ->with(['user', 'merchant'])
            ->when(!empty($filters['merchant_id']), fn (Builder $q) => $q->where('merchant_id', $filters['merchant_id']))
            ->when(!empty($filters['action']), fn (Builder $q) => $q->where('action', $filters['action']))
            ->when(!empty($filters['entity_type']), fn (Builder $q) => $q->where('entity_type', $filters['entity_type']))
            ->when(!empty($filters['actor_type']), fn (Builder $q) => $q->where('actor_type', $filters['actor_type']))
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Mask PCI-DSS cardholder and sensitive authentication data recursively.
     */
    public function scrubSensitiveData(array $data): array
    {
        $scrubbed = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            if (is_array($value)) {
                $scrubbed[$key] = $this->scrubSensitiveData($value);
                continue;
            }

            if (!is_string($value)) {
                $scrubbed[$key] = $value;
                continue;
            }

            // 1. CVC / CVV / PIN / Security codes: NEVER store
            if (in_array($lowerKey, ['cvv', 'cvc', 'security_code', 'pin', 'password', 'password_confirmation'], true)) {
                $scrubbed[$key] = '***';
                continue;
            }

            // 2. Secret API keys: tiq_live_sec_... / tiq_test_sec_... / whsec_...
            if (str_starts_with($value, 'tiq_') || str_starts_with($value, 'whsec_') || str_contains($lowerKey, 'secret')) {
                $prefix = substr($value, 0, 12);
                $scrubbed[$key] = $prefix . '****************' . substr($value, -4);
                continue;
            }

            // 3. Card PAN (13 to 19 digits)
            $numeric = preg_replace('/\D/', '', $value);
            if (strlen($numeric) >= 13 && strlen($numeric) <= 19 && (str_contains($lowerKey, 'card') || str_contains($lowerKey, 'pan') || str_contains($lowerKey, 'number'))) {
                $bin = substr($numeric, 0, 6);
                $last4 = substr($numeric, -4);
                $scrubbed[$key] = $bin . str_repeat('*', strlen($numeric) - 10) . $last4;
                continue;
            }

            $scrubbed[$key] = $value;
        }

        return $scrubbed;
    }
}
