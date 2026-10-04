<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\AuditLogService;
use App\Services\Security\RiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected RiskService $riskService
    ) {}

    /**
     * List immutable audit logs with multi-attribute filtering.
     * Accessible by: admin, auditor, operations.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $filters = $request->only(['action', 'entity_type', 'actor_type']);
        if ($merchant) {
            $filters['merchant_id'] = $merchant->id;
        }

        $perPage = min(100, (int) $request->query('per_page', 25));
        $logs = $this->auditLogService->queryLogs($filters, $perPage);

        // Annotate each record with its real-time cryptographic integrity status
        $annotatedItems = collect($logs->items())->map(function ($log) {
            $isValid = $this->auditLogService->verifyRecordIntegrity($log);
            $array = $log->toArray();
            $array['is_tamper_free'] = $isValid;
            return $array;
        });

        return response()->json([
            'status' => 'success',
            'data' => $annotatedItems,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Run cryptographic chain verification across all audit records.
     */
    public function verifyAuditChain(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $result = $this->auditLogService->verifyChainIntegrity($merchant?->id);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * Retrieve real-time payment risk telemetry and heuristics rules.
     */
    public function riskMetrics(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $metrics = $this->riskService->getRiskMetrics($merchant?->id);

        return response()->json([
            'status' => 'success',
            'data' => $metrics,
        ]);
    }

    /**
     * Evaluate arbitrary payment payload against risk rules (Operator Simulator).
     */
    public function evaluateRiskSimulator(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'amount' => 'required|integer|min:1',
            'currency' => 'nullable|string|size:3',
            'customer_email' => 'required|email',
            'card_number' => 'nullable|string',
        ]);

        $result = $this->riskService->evaluateSimulator($payload);

        return response()->json([
            'status' => 'success',
            'data' => $result->toArray(),
        ]);
    }

    /**
     * Institutional RBAC Matrix: List roles, descriptions, policies, and assigned users.
     */
    public function rolesAndUsers(): JsonResponse
    {
        $roles = Role::with(['users:id,name,email,role_id,status,phone,created_at'])->get();

        $roleDefinitions = [
            'admin' => [
                'title' => 'Administrator',
                'description' => 'Universal root access across all platform entities, API keys, merchant accounts, and security controls.',
                'permissions' => ['*'],
                'risk_level' => 'CRITICAL',
            ],
            'merchant' => [
                'title' => 'Merchant Operator',
                'description' => 'Direct access scoped strictly to own merchant transactions, balance ledger, settlements, and webhooks.',
                'permissions' => ['payments:write', 'payments:read', 'settlements:read', 'webhooks:read'],
                'risk_level' => 'STANDARD',
            ],
            'auditor' => [
                'title' => 'Financial & Compliance Auditor',
                'description' => 'Read-only inspection rights across all transactions, double-entry ledgers, and tamper-evident audit trails.',
                'permissions' => ['payments:read', 'ledger:read', 'audit:read', 'reconciliation:read'],
                'risk_level' => 'LOW',
            ],
            'operations' => [
                'title' => 'Operations Officer',
                'description' => 'Investigates payment declines, initiates manual webhook replays, and resolves reconciliation discrepancies.',
                'permissions' => ['payments:read', 'webhooks:write', 'reconciliation:resolve', 'risk:read'],
                'risk_level' => 'ELEVATED',
            ],
        ];

        $enrichedRoles = $roles->map(function ($role) use ($roleDefinitions) {
            $def = $roleDefinitions[$role->slug] ?? [
                'title' => $role->name,
                'description' => $role->description,
                'permissions' => [],
                'risk_level' => 'STANDARD',
            ];

            return [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $def['description'],
                'permissions' => $def['permissions'],
                'risk_level' => $def['risk_level'],
                'users_count' => $role->users->count(),
                'users' => $role->users,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'roles' => $enrichedRoles,
                'total_users' => User::count(),
            ],
        ]);
    }
}
