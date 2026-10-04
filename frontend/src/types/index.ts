export type TransactionStatus = 'INITIATED' | 'PROCESSING' | 'SUCCESS' | 'FAILED' | 'PENDING' | 'REVERSED';

export type AccountType = 'ASSET' | 'LIABILITY' | 'EQUITY' | 'REVENUE' | 'EXPENSE';

export interface HealthService {
  status: 'connected' | 'disconnected' | 'unknown';
  latency_ms?: number;
  engine?: string;
  version?: string;
  response?: string;
  error?: string;
}

export interface SystemHealth {
  platform: string;
  version: string;
  environment: string;
  status: 'healthy' | 'degraded' | 'down';
  timestamp: string;
  total_latency_ms: number;
  services: {
    database: HealthService;
    redis: HealthService;
  };
}

export interface PaymentAttemptItem {
  attempt_number: number;
  provider: string;
  provider_reference?: string;
  status: string;
  error_code?: string;
  error_message?: string;
  gateway_response?: Record<string, any>;
  latency_ms: number;
  created_at: string;
}

export interface Transaction {
  id: string;
  reference: string;
  merchant_id: string;
  merchant_name?: string;
  customer_email?: string;
  amount: number; // in minor units (kobo/cents)
  fee_amount: number;
  net_amount: number;
  currency: string;
  status: TransactionStatus;
  payment_method: string;
  idempotency_key?: string;
  provider?: string;
  provider_reference?: string;
  failure_reason?: string;
  payment_attempts?: PaymentAttemptItem[];
  created_at: string;
}

export interface LedgerAccount {
  account_number: string;
  name: string;
  type: AccountType;
  classification: string;
  currency: string;
  balance: number; // minor units
  owner: string;
}

export interface LedgerEntry {
  id: string;
  reference: string;
  entry_type: string;
  debit_account: string;
  credit_account: string;
  amount: number;
  currency: string;
  description: string;
  created_at: string;
}

export interface WebhookItem {
  id: string;
  merchant_id?: string;
  transaction_id?: string;
  event_type: string;
  endpoint_url: string;
  signature: string;
  payload?: Record<string, any>;
  attempts: number;
  max_attempts: number;
  status: 'DELIVERED' | 'FAILED' | 'PENDING' | 'RETRYING';
  response_status?: number;
  response_body?: string;
  next_retry_at?: string;
  delivered_at?: string;
  created_at: string;
}

export interface ReconciliationExceptionItem {
  id: string;
  reconciliation_run_id: string;
  run_reference?: string;
  transaction_id?: string;
  internal_reference?: string;
  provider_reference?: string;
  exception_type: 'MISSING_IN_INTERNAL' | 'MISSING_IN_PROVIDER' | 'AMOUNT_MISMATCH' | 'STATUS_MISMATCH';
  internal_amount_minor?: number;
  provider_amount_minor?: number;
  internal_status?: string;
  provider_status?: string;
  discrepancy_details?: Record<string, any>;
  status: 'OPEN' | 'INVESTIGATING' | 'RESOLVED' | 'WRITTEN_OFF';
  resolution_notes?: string;
  resolved_at?: string;
  created_at: string;
}

export interface ReconciliationRunItem {
  id?: string;
  run_reference: string;
  provider: string;
  source_file?: string;
  reconciliation_date: string;
  total_internal_records?: number;
  total_provider_records?: number;
  total_records?: number;
  matched_records: number;
  mismatched_records: number;
  match_rate_percent?: number;
  matched_volume_minor?: number;
  mismatched_volume_minor?: number;
  status: 'PROCESSING' | 'COMPLETED' | 'FAILED';
  summary?: Record<string, any>;
  created_at?: string;
  exceptions?: ReconciliationExceptionItem[];
}

// Backward compatibility alias
export type ReconciliationSummary = ReconciliationRunItem;

export interface ApiKeyItem {
  type: 'LIVE' | 'TEST';
  public_key: string;
  secret_preview: string;
  scopes: string[];
  status: 'ACTIVE' | 'REVOKED';
}

export interface Settlement {
  id: string;
  merchant_id?: string;
  settlement_reference: string;
  status: 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED';
  currency: string;
  gross_amount: number;
  fee_amount: number;
  net_amount: number;
  transaction_count: number;
  settlement_account?: string;
  settlement_bank?: string;
  payout_reference?: string;
  initiated_at?: string;
  completed_at?: string;
  created_at: string;
  transactions?: Transaction[];
}

// Stage 10: Security, Audit Trails, RBAC & Risk Rules
export interface AuditLogItem {
  id: string;
  user_id?: string | null;
  merchant_id?: string | null;
  actor_type: 'SYSTEM' | 'API' | 'OPERATIONS' | 'MERCHANT' | 'USER';
  action: string;
  entity_type: string;
  entity_id: string;
  old_values?: Record<string, any> | null;
  new_values?: Record<string, any> | null;
  ip_address?: string;
  user_agent?: string;
  created_at: string;
  is_tamper_free?: boolean;
  user?: {
    id: number;
    name: string;
    email: string;
  };
  merchant?: {
    id: string;
    name: string;
    merchant_code: string;
  };
}

export interface AuditChainStatus {
  total_records: number;
  valid_records: number;
  is_chain_healthy: boolean;
  corrupted_records: any[];
  genesis_hash: string;
  latest_hash: string;
}

export interface RiskRuleItem {
  id: string;
  name: string;
  description: string;
  status: string;
  threshold: string;
  action: string;
}

export interface RiskMetrics {
  total_evaluated: number;
  total_blocked: number;
  block_rate_percentage: number;
  active_rules_count: number;
  rules: RiskRuleItem[];
  evaluated_at: string;
}

export interface RoleUserItem {
  id: number;
  name: string;
  email: string;
  status: string;
  phone?: string;
  created_at?: string;
}

export interface RolePolicyItem {
  id: number;
  name: string;
  slug: string;
  description: string;
  permissions: string[];
  risk_level: 'CRITICAL' | 'ELEVATED' | 'STANDARD' | 'LOW';
  users_count: number;
  users?: RoleUserItem[];
}

export interface RiskSimulationPayload {
  amount: number;
  currency?: string;
  customer_email: string;
  card_number?: string;
}

export interface RiskSimulationResult {
  score: number;
  decision: 'ALLOW' | 'REVIEW' | 'BLOCK';
  flags: string[];
  reason: string;
  metadata?: Record<string, any>;
}

export interface AuthUser {
  id: string;
  name: string;
  email: string;
  role: 'admin' | 'operations' | 'auditor' | 'merchant' | string;
  role_name: string;
  merchant?: {
    id: string;
    name: string;
    merchant_code: string;
  } | null;
}

export interface LoginResponse {
  status: string;
  message: string;
  data: {
    token: string;
    user: AuthUser;
  };
}

export interface PaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface AnalyticsSummary {
  overview: {
    cleared_volume: number;
    fee_revenue: number;
    net_payout_volume: number;
    currency: string;
    total_transactions: number;
    successful_transactions: number;
    failed_transactions: number;
    pending_transactions: number;
    success_rate: number;
  };
  settlements: {
    pending_volume: number;
    completed_batches: number;
  };
  ledger_health: {
    is_balanced: boolean;
    total_debits: number;
    total_credits: number;
    net_variance: number;
    total_entries: number;
  };
  recent_transactions: {
    id: string;
    reference: string;
    amount: number;
    fee_amount: number;
    net_amount: number;
    currency: string;
    status: string;
    payment_method: string;
    customer_email: string;
    created_at: string;
  }[];
}

