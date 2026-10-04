# TransactIQ — System Architecture & Implementation Blueprint

> **Intelligent Payment Processing, Reconciliation & Settlement Platform**  
> *A Production-Grade Fintech Infrastructure & Financial Operations Engine*

---

## 1. Executive Summary & Vision

**TransactIQ** is designed not merely as a payment processing interface, but as a **comprehensive payment infrastructure and financial operations platform**. In modern digital finance, moving money reliably is only half the battle; the real engineering challenges emerge around **idempotency, distributed state machines, failure recovery, event-driven webhooks, double-entry financial ledgers, automated multi-source reconciliation, settlement batching, immutable audit trails, and anomaly detection**.

TransactIQ solves these problems by providing a resilient, audited, and observable backend paired with an intuitive operational dashboard for merchants, administrators, auditors, and operations officers.

---

## 2. Core Problems & Engineering Solutions

| # | Challenge | Real-World Scenario | TransactIQ Architecture Solution |
|---|-----------|---------------------|----------------------------------|
| **1** | **Duplicate Transactions** | Customer clicks "Pay" twice on high network latency. | **Idempotency Engine**: Dedicated idempotency keys with distributed Redis lock + PostgreSQL unique constraint. Repeated requests return cached responses without re-executing charges. |
| **2** | **Ambiguous Transaction States** | Gateway network times out midway; payment status is unknown. | **Deterministic State Machine**: Strict transitions (`INITIATED` → `PROCESSING` → `SUCCESS` / `FAILED` / `PENDING`), event history, and background poller/re-querying. |
| **3** | **Record Discrepancies** | Merchant logs 100 successful orders, provider records only 98. | **Automated Reconciliation Engine**: Compares internal ledger/transactions against provider clearing files/webhooks, detects mismatches, and isolates exceptions. |
| **4** | **Webhook Delivery Failures** | Merchant server is down during a payment success event. | **Reliable Webhook Engine**: Signed payloads (HMAC-SHA256), exponential backoff retries with jitter, delivery audit log, and manual replay capability. |
| **5** | **Traceability & Financial Integrity** | Dispute arises or record balance drifts without clear origin. | **Double-Entry General Ledger & Audit Trail**: Every monetary movement has balanced debits/credits; every mutation creates an immutable audit log entry. |
| **6** | **Suspicious Activities & Fraud** | Rapid burst of high-value cards or abnormal IP velocity. | **Rule-Based & ML Anomaly Service**: Velocity throttles, anomalous transaction flags, risk scoring, and operational review queues. |

---

## 3. System Architecture & Component Topology

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           CLIENT / INTEGRATION LAYER                    │
│   ┌─────────────────────────────┐        ┌──────────────────────────┐   │
│   │ Merchant API Consumers      │        │ Operations / Admin       │   │
│   │ (E-Commerce, POS, Mobile)   │        │ React + TS Dashboard     │   │
│   └──────────────┬──────────────┘        └────────────┬─────────────┘   │
└──────────────────┼────────────────────────────────────┼─────────────────┘
                   │ HTTPS / REST                       │ HTTPS / REST
                   ▼                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                    TRANSACTIQ CORE API (Laravel / PHP)                  │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ Middleware: Auth (Sanctum/API Key), Rate Limiting, Idempotency   │  │
│  └──────────────────────────────────┬───────────────────────────────┘  │
│                                     ▼                                   │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ Domain Services:                                                 │  │
│  │  • Transaction Engine (FSM, Validations, Attempt Tracking)       │  │
│  │  • Double-Entry Ledger Service (Debits, Credits, Balances)        │  │
│  │  • Payment Provider Gateway Adapter (Simulated / Sandboxes)      │  │
│  │  • Reconciliation Engine (File Parser, Matcher, Exception Flag)  │  │
│  │  • Settlement Processor (Fee Calculation, Batching, Payouts)     │  │
│  │  • Webhook Dispatcher (HMAC Signing, Event Publishing)           │  │
│  │  • Audit Logging Service (Actor, Entity, Diffs, Metadata)        │  │
│  └──────────────────┬───────────────────────────────┬───────────────┘  │
└─────────────────────┼───────────────────────────────┼───────────────────┘
                      │                               │
                      ▼                               ▼
       ┌────────────────────────────┐   ┌──────────────────────────┐
       │   Asynchronous Worker      │   │ Persistent Storage       │
       │   (Redis Queue + Workers)  │   │ (PostgreSQL / MySQL 8)   │
       │  • Webhook Delivery & Retry│   │  • Transactions, Ledgers │
       │  • Settlement Batch Jobs   │   │  • Merchants, Users      │
       │  • Reconciliation Matcher  │   │  • Audit Trail, Disputes │
       └──────────────┬─────────────┘   └──────────────────────────┘
                      │
                      ▼ (Phase 8)
       ┌────────────────────────────┐
       │ Intelligence & Anomaly Svc │
       │ (Python + FastAPI)         │
       │  • Pattern Risk Scoring    │
       │  • Anomaly Detection Model │
       └────────────────────────────┘
```

---

## 4. Domain Data Model & Database Entities

### 4.1 Authentication & Multi-Tenancy
- **`users`**: Platform administrators, auditors, merchant operators, operations specialists.
- **`merchants`**: Businesses utilizing TransactIQ; includes KYC status, currency config, fee schedule, webhook endpoint.
- **`api_keys`**: Public/secret key pairs (`tiq_live_pub_...`, `tiq_live_sec_...`), scopes, expiry, IP whitelists.

### 4.2 Payments & Transactions
- **`customers`**: Identifier, email, phone number, merchant reference.
- **`transactions`**:
  - `id`: UUID primary key.
  - `reference`: Human-readable identifier (`TXN-YYYYMMDD-XXXXXX`).
  - `merchant_id`: Foreign key to merchants.
  - `amount`: Integer in minor units (e.g., kobo/cents) to avoid floating-point errors.
  - `currency`: ISO-4217 code (NGN, USD, EUR, GBP).
  - `status`: `INITIATED`, `PROCESSING`, `SUCCESS`, `FAILED`, `PENDING`, `REVERSED`.
  - `idempotency_key`: Unique string provided by client.
  - `provider_reference`: Reference from payment provider simulator.
  - `metadata`: JSON payload for arbitrary merchant attributes.
- **`transaction_events`**: Immutable chronological history of each status transition, actor/trigger, and provider raw response.
- **`payment_attempts`**: Individual execution tries for a transaction (tracking timeouts, retries, switch between fallback gateways).

### 4.3 Double-Entry Financial Ledger
- **`ledger_accounts`**:
  - Types: `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, `EXPENSE`.
  - Specific accounts: `MERCHANT_AVAILABLE_BALANCE`, `MERCHANT_PENDING_SETTLEMENT`, `PLATFORM_FEE_REVENUE`, `PROVIDER_CLEARING_ACCOUNT`.
- **`ledger_entries`**:
  - Immutable rows containing `transaction_id`, `account_id`, `direction` (`DEBIT` or `CREDIT`), `amount`, and `created_at`.
  - Invariant: `SUM(DEBITS) == SUM(CREDITS)` for every financial event.

### 4.4 Webhook Notification System
- **`webhook_deliveries`**:
  - Fields: `event_type` (`payment.success`, `payment.failed`, `dispute.created`, `settlement.completed`), `merchant_id`, `payload`, `response_status`, `response_body`, `attempt_number`, `next_retry_at`, `status` (`DELIVERED`, `FAILED`, `PENDING`).
  - Signing: HMAC-SHA256 with merchant webhook secret header `X-TransactIQ-Signature`.

### 4.5 Reconciliation Engine
- **`reconciliation_runs`**: Batch job records comparing internal transactions against external provider clearing files.
- **`reconciliation_exceptions`**:
  - Types: `MISSING_IN_PROVIDER`, `MISSING_IN_INTERNAL`, `AMOUNT_MISMATCH`, `STATUS_MISMATCH`.
  - Resolution workflow: `OPEN`, `UNDER_REVIEW`, `RESOLVED`, `WRITTEN_OFF`.

### 4.6 Settlement & Disputes
- **`settlements`**: Batches of eligible successful transactions grouped by settlement cycle (e.g., T+1). Calculates Gross Volume, Platform Fees, and Net Payout.
- **`disputes`**: Chargebacks and transaction claims; holds funds in escrow during review.

### 4.7 Security & Auditing
- **`audit_logs`**: Tamper-evident record of user actions: `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`.

---

## 5. State Machine: Transaction Lifecycle

```
               ┌───────────────┐
               │   INITIATED   │
               └───────┬───────┘
                       │ (Validate & dispatch to provider)
                       ▼
               ┌───────────────┐
               │  PROCESSING   │
               └───┬───┬───┬───┘
     Provider      │   │   │ Provider
     Success       │   │   │ Failed
        ┌──────────┘   │   └──────────┐
        ▼              ▼              ▼
 ┌─────────────┐ ┌───────────┐ ┌─────────────┐
 │   SUCCESS   │ │  PENDING  │ │   FAILED    │
 └──────┬──────┘ └─────┬─────┘ └─────────────┘
        │              │
        │              │ Async Webhook / Query
        │              ├───────────┐
        │              ▼           ▼
        │        ┌───────────┐ ┌───────────┐
        │        │  SUCCESS  │ │  FAILED   │
        │        └─────┬─────┘ └───────────┘
        ▼              ▼
 ┌───────────────────────────┐
 │ Ledger debited/credited   │
 │ Webhook dispatched        │
 │ Eligible for settlement   │
 └───────────────────────────┘
```

---

## 6. Implementation Roadmap

- **Phase 1 — Foundation**: Directory setup, database configurations, base authentication & multi-tenant merchant models, responsive dashboard UI structure.
- **Phase 2 — Payment Core API**: Secure merchant API authentication, payment initiation endpoint, strict validation, transaction reference generator, deterministic state engine.
- **Phase 3 — Reliability & Idempotency**: Distributed idempotency keys, DB atomic transactions, simulated payment gateway with deterministic test cards and network edge cases (slow, failure, drop).
- **Phase 4 — Webhook Delivery Engine**: Asynchronous job queue, HMAC-SHA256 signature generation, exponential backoff retries with delivery logging, merchant test webhook listener.
- **Phase 5 — Double-Entry Ledger & Settlement Engine**: Immutable ledger accounts and entries, fee calculation, settlement batching (T+1 payouts), balance statements.
- **Phase 6 — Reconciliation Engine**: Simulated external bank/provider clearing report generator, automated discrepancy detection, exception management dashboard.
- **Phase 7 — Enterprise Security, RBAC & Audit Trails**: Role-based access control (Admin, Merchant, Auditor, Ops), IP rate limiting, tamper-resistant audit logging.
- **Phase 8 — Intelligence & Anomaly Detection**: Velocity check rules engine, risk scoring, FastAPI Python microservice integration.
- **Phase 9 — Production Readiness & DevOps**: Docker orchestration, automated CI/CD workflows, stress & failure-path testing, API documentation (Swagger/OpenAPI).
