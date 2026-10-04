import type {
  SystemHealth,
  LedgerAccount,
  LedgerEntry,
  Settlement,
  ReconciliationRunItem,
  ReconciliationExceptionItem,
  AuditLogItem,
  AuditChainStatus,
  RiskMetrics,
  RolePolicyItem,
  RiskSimulationPayload,
  RiskSimulationResult,
  AuthUser,
  LoginResponse,
  Transaction,
  PaginationMeta,
  AnalyticsSummary,
} from '../types';

const API_BASE = '/api/v1';

export const apiService = {
  /**
   * Fetch live infrastructure health (PostgreSQL + Redis + API)
   */
  async getHealth(): Promise<SystemHealth> {
    try {
      const response = await fetch(`${API_BASE}/health`, {
        headers: { 'Accept': 'application/json' },
      });
      if (!response.ok && response.status !== 503) {
        throw new Error(`HTTP ${response.status}`);
      }
      return await response.json();
    } catch (error) {
      return {
        platform: 'TransactIQ Core Infrastructure',
        version: '1.0.0',
        environment: 'local',
        status: 'down',
        timestamp: new Date().toISOString(),
        total_latency_ms: 0,
        services: {
          database: { status: 'disconnected', error: String(error) },
          redis: { status: 'disconnected', error: 'Service unreachable' },
        },
      };
    }
  },

  /**
   * Format minor units (kobo/cents) into clean currency display
   */
  formatMoney(amountMinor: number, currency = 'NGN'): string {
    const symbol = currency === 'NGN' ? '₦' : currency === 'USD' ? '$' : '€';
    return `${symbol}${(amountMinor / 100).toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;
  },

  /**
   * Execute real payment request against TransactIQ API with merchant credentials
   */
  async processPayment(
    apiKey: string,
    idempotencyKey: string,
    payload: {
      amount: number;
      currency: string;
      payment_method: string;
      customer: { email: string; name?: string; phone?: string };
      card?: { number: string; exp_month: string; exp_year: string; cvv: string };
      gateway_simulation?: string;
      metadata?: Record<string, any>;
    }
  ): Promise<{ status: string; data: any; replayed?: boolean; error?: string; message?: string }> {
    const response = await fetch(`${API_BASE}/payments`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
        'Idempotency-Key': idempotencyKey,
      },
      body: JSON.stringify(payload),
    });

    const isReplayed = response.headers.get('X-Idempotent-Replay') === 'true';
    const json = await response.json();
    return { ...json, replayed: isReplayed };
  },

  /**
   * Fetch paginated transactions from backend with optional status filtering
   */
  async getTransactions(
    apiKey: string,
    page = 1,
    perPage = 20,
    status?: string
  ): Promise<{ data: Transaction[]; pagination: PaginationMeta }> {
    const params = new URLSearchParams();
    params.append('page', String(page));
    params.append('per_page', String(perPage));
    if (status && status !== 'ALL') {
      params.append('status', status);
    }

    const response = await fetch(`${API_BASE}/payments?${params.toString()}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });

    if (!response.ok) {
      throw new Error(`Failed to load transactions: HTTP ${response.status}`);
    }

    const json = await response.json();
    return {
      data: (json.data || []).map((t: any) => ({
        id: t.id,
        reference: t.reference,
        merchant_id: t.merchant_id || '',
        customer_email: t.customer?.email || 'N/A',
        amount: t.amount,
        fee_amount: t.fee_amount,
        net_amount: t.net_amount,
        currency: t.currency,
        status: t.status,
        payment_method: t.payment_method,
        idempotency_key: t.idempotency_key,
        provider: t.provider || 'SIMULATED_GATEWAY',
        created_at: t.created_at,
      })),
      pagination: json.pagination || {
        current_page: page,
        per_page: perPage,
        total: (json.data || []).length,
        last_page: 1,
      },
    };
  },

  /**
   * Fetch full details of a transaction including event timeline and gateway attempts
   */
  async getTransactionDetails(apiKey: string, reference: string): Promise<any> {
    const response = await fetch(`${API_BASE}/payments/${encodeURIComponent(reference)}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });

    if (!response.ok) {
      throw new Error(`Failed to load transaction details: HTTP ${response.status}`);
    }

    const json = await response.json();
    return json.data;
  },

  /**
   * Fetch aggregated operational analytics summary and ledger health
   */
  async getAnalyticsSummary(apiKey: string): Promise<AnalyticsSummary> {
    const response = await fetch(`${API_BASE}/analytics/summary`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });

    if (!response.ok) {
      throw new Error(`Failed to load analytics summary: HTTP ${response.status}`);
    }

    const json = await response.json();
    return json.data;
  },

  /**
   * Fetch webhook delivery logs for the merchant with optional status filter
   */
  async getWebhooks(apiKey: string, status?: string): Promise<any[]> {
    const query = status && status !== 'ALL' ? `?status=${encodeURIComponent(status)}` : '';
    const response = await fetch(`${API_BASE}/webhooks${query}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load webhooks: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data || [];
  },

  /**
   * Fetch single webhook delivery with full payload and response body
   */
  async getWebhookDetails(apiKey: string, id: string): Promise<any> {
    const response = await fetch(`${API_BASE}/webhooks/${id}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load webhook details: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data;
  },

  /**
   * Trigger manual replay of a webhook delivery
   */
  async replayWebhook(apiKey: string, id: string): Promise<any> {
    const response = await fetch(`${API_BASE}/webhooks/${id}/replay`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to replay webhook: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data;
  },

  /**
   * Fetch merchant's chart of accounts
   */
  async getLedgerAccounts(apiKey: string): Promise<LedgerAccount[]> {
    const response = await fetch(`${API_BASE}/ledger/accounts`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load ledger accounts: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data || [];
  },

  /**
   * Fetch journal entries with debits and credits
   */
  async getLedgerEntries(apiKey: string, limit = 50): Promise<LedgerEntry[]> {
    const response = await fetch(`${API_BASE}/ledger/entries?limit=${limit}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load ledger entries: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data || [];
  },

  /**
   * Audit ledger double-entry mathematical integrity
   */
  async getLedgerIntegrity(apiKey: string): Promise<{
    balanced: boolean;
    total_debit: number;
    total_credit: number;
    net_variance: number;
    total_entries: number;
  }> {
    const response = await fetch(`${API_BASE}/ledger/integrity`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to check ledger integrity: HTTP ${response.status}`);
    }
    const json = await response.json();
    const d = json.data || {};
    return {
      balanced: d.is_balanced ?? true,
      total_debit: d.total_debits ?? 0,
      total_credit: d.total_credits ?? 0,
      net_variance: d.discrepancy ?? 0,
      total_entries: d.total_entries ?? 0,
    };
  },

  /**
   * Fetch settlement batches with optional status filter
   */
  async getSettlements(apiKey: string, status?: string): Promise<Settlement[]> {
    const query = status && status !== 'ALL' ? `?status=${encodeURIComponent(status)}` : '';
    const response = await fetch(`${API_BASE}/settlements${query}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load settlements: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data || [];
  },

  /**
   * Fetch single settlement batch details
   */
  async getSettlementDetails(apiKey: string, id: string): Promise<Settlement> {
    const response = await fetch(`${API_BASE}/settlements/${id}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load settlement details: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data;
  },

  /**
   * Trigger automated settlement batch generation (T+1)
   */
  async generateSettlementBatch(apiKey: string, currency = 'NGN'): Promise<{ status: string; message: string; data?: any }> {
    const response = await fetch(`${API_BASE}/settlements/generate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
      body: JSON.stringify({ currency }),
    });
    return await response.json();
  },

  /**
   * Mark a settlement payout as fulfilled via wire payout
   */
  async completeSettlementPayout(apiKey: string, id: string, payoutReference?: string): Promise<{ status: string; message: string; data?: any }> {
    const response = await fetch(`${API_BASE}/settlements/${id}/complete`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
      body: JSON.stringify({ payout_reference: payoutReference }),
    });
    return await response.json();
  },

  /**
   * Fetch all reconciliation audit runs
   */
  async getReconciliationRuns(apiKey: string, status?: string): Promise<ReconciliationRunItem[]> {
    const query = status && status !== 'ALL' ? `?status=${encodeURIComponent(status)}` : '';
    const response = await fetch(`${API_BASE}/reconciliation/runs${query}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load reconciliation runs: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data || [];
  },

  /**
   * Fetch single reconciliation run details with exceptions
   */
  async getReconciliationRunDetails(apiKey: string, id: string): Promise<ReconciliationRunItem> {
    const response = await fetch(`${API_BASE}/reconciliation/runs/${id}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load reconciliation run details: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data;
  },

  /**
   * Trigger reconciliation process via raw text content or file upload
   */
  async processReconciliation(
    apiKey: string,
    payload: { provider?: string; date?: string; content?: string; format?: string; records?: any[] }
  ): Promise<{ status: string; message: string; data?: ReconciliationRunItem }> {
    const response = await fetch(`${API_BASE}/reconciliation/process`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
      body: JSON.stringify(payload),
    });
    return await response.json();
  },

  /**
   * Generate realistic simulated clearing report (optionally auto-running audit)
   */
  async generateSampleClearingFile(apiKey: string, format = 'csv', autoRun = true): Promise<any> {
    const response = await fetch(`${API_BASE}/reconciliation/generate-sample-file`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
      body: JSON.stringify({ format, auto_run: autoRun }),
    });
    return await response.json();
  },

  /**
   * List reconciliation exceptions across runs
   */
  async getReconciliationExceptions(apiKey: string, runId?: string, status?: string): Promise<ReconciliationExceptionItem[]> {
    const params = new URLSearchParams();
    if (runId) params.append('run_id', runId);
    if (status && status !== 'ALL') params.append('status', status);

    const qs = params.toString() ? `?${params.toString()}` : '';
    const response = await fetch(`${API_BASE}/reconciliation/exceptions${qs}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load reconciliation exceptions: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data || [];
  },

  /**
   * Resolve an exception via operations triage
   */
  async resolveReconciliationException(apiKey: string, id: string, action: string, notes?: string): Promise<any> {
    const response = await fetch(`${API_BASE}/reconciliation/exceptions/${id}/resolve`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
      body: JSON.stringify({ action, notes }),
    });
    return await response.json();
  },

  /**
   * Stage 10: Fetch immutable audit logs
   */
  async getAuditLogs(
    apiKey: string,
    filters?: { action?: string; entity_type?: string; actor_type?: string; per_page?: number }
  ): Promise<{ data: AuditLogItem[]; meta: any }> {
    const params = new URLSearchParams();
    if (filters?.action) params.append('action', filters.action);
    if (filters?.entity_type) params.append('entity_type', filters.entity_type);
    if (filters?.actor_type) params.append('actor_type', filters.actor_type);
    if (filters?.per_page) params.append('per_page', String(filters.per_page));

    const qs = params.toString() ? `?${params.toString()}` : '';
    const response = await fetch(`${API_BASE}/security/audit-logs${qs}`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load audit logs: HTTP ${response.status}`);
    }
    const json = await response.json();
    return { data: json.data || [], meta: json.meta || {} };
  },

  /**
   * Stage 10: Verify cryptographic hash chain integrity
   */
  async verifyAuditChain(apiKey: string): Promise<AuditChainStatus> {
    const response = await fetch(`${API_BASE}/security/audit-logs/verify-chain`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to verify audit chain: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data;
  },

  /**
   * Stage 10: Fetch real-time payment risk telemetry and heuristics rules
   */
  async getRiskMetrics(apiKey: string): Promise<RiskMetrics> {
    const response = await fetch(`${API_BASE}/security/risk-metrics`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load risk metrics: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data;
  },

  /**
   * Stage 10: Institutional RBAC matrix (Roles and assigned users)
   */
  async getRolesAndUsers(apiKey: string): Promise<{ roles: RolePolicyItem[]; total_users: number }> {
    const response = await fetch(`${API_BASE}/security/roles-and-users`, {
      headers: {
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
    });
    if (!response.ok) {
      throw new Error(`Failed to load roles and users: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data;
  },

  /**
   * Stage 10: Test payment payload against risk heuristics simulator
   */
  async evaluateRiskSimulator(apiKey: string, payload: RiskSimulationPayload): Promise<RiskSimulationResult> {
    const response = await fetch(`${API_BASE}/security/risk-rules/evaluate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Api-Key': apiKey,
      },
      body: JSON.stringify(payload),
    });
    if (!response.ok) {
      throw new Error(`Risk simulator evaluation failed: HTTP ${response.status}`);
    }
    const json = await response.json();
    return json.data;
  },

  /**
   * Stage 11: Authenticate platform user and store Sanctum token
   */
  async login(email: string, password = 'Password123!'): Promise<LoginResponse> {
    const response = await fetch(`${API_BASE}/auth/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({ email, password }),
    });
    const json = await response.json();
    if (!response.ok) {
      throw new Error(json.message || 'Authentication failed');
    }
    if (json.data?.token) {
      localStorage.setItem('transactiq_user_token', json.data.token);
      localStorage.setItem('transactiq_user_profile', JSON.stringify(json.data.user));
    }
    return json;
  },

  /**
   * Stage 11: Log out platform user and clear session
   */
  async logout(): Promise<void> {
    const token = localStorage.getItem('transactiq_user_token');
    if (token) {
      try {
        await fetch(`${API_BASE}/auth/logout`, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
        });
      } catch (err) {
        console.warn('Logout API call failed:', err);
      }
    }
    localStorage.removeItem('transactiq_user_token');
    localStorage.removeItem('transactiq_user_profile');
  },

  /**
   * Stage 11: Get current user from local storage
   */
  getStoredUser(): AuthUser | null {
    const raw = localStorage.getItem('transactiq_user_profile');
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  },

  /**
   * Stage 11: Get active Sanctum token
   */
  getStoredToken(): string | null {
    return localStorage.getItem('transactiq_user_token');
  },
};

