# TransactIQ — Institutional Payment Processing, Ledger & Settlement Platform

[![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![React](https://img.shields.io/badge/React-19-61DAFB?style=for-the-badge&logo=react&logoColor=black)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.9-3178C6?style=for-the-badge&logo=typescript&logoColor=white)](https://www.typescriptlang.org)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?style=for-the-badge&logo=postgresql&logoColor=white)](https://www.postgresql.org)
[![Redis](https://img.shields.io/badge/Redis-7-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://www.docker.com)
[![OpenAPI](https://img.shields.io/badge/OpenAPI-3.0-6BA539?style=for-the-badge&logo=openapiinitiative&logoColor=white)](docs)
[![Tests](https://img.shields.io/badge/Tests-68%2F68%20Passed-brightgreen?style=for-the-badge)](tests)

> **TransactIQ** is an institutional-grade financial infrastructure and payment operations platform engineered to solve core digital payment challenges: duplicate charges, ambiguous transaction states, zero-drift double-entry ledger accounting, automated multi-source reconciliation, T+1 settlement batching, immutable chained audit trails, and payment fraud defense.

---

## 1. System Topology & Architecture

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                               TRANSACTIQ TOPOLOGY MATRIX                                │
│                                                                                         │
│   [Merchant App / Client]                 [Fintech Operations Portal (React 19 + TS)]   │
│             │                                                  │                        │
│             ▼                                                  ▼                        │
│   ┌─────────────────────────────────────────────────────────────────────────────────┐   │
│   │                          API GATEWAY & SECURITY LAYER                           │   │
│   │   • SHA-256 API Key & Sanctum Auth       • Distributed Idempotency (Redis Lock) │   │
│   │   • Institutional Role-Based RBAC        • Payload Hash Fingerprinting (SHA-256)│   │
│   │   • Rate Limiting (60 req/min)           • PCI-DSS Sensitive Field Scrubbing    │   │
│   └────────────────────────────────────────┬────────────────────────────────────────┘   │
│                                            ▼                                            │
│   ┌─────────────────────────────────────────────────────────────────────────────────┐   │
│   │                              CORE DOMAIN ENGINES                                │   │
│   │   1. Deterministic State Machine (FSM)     2. Multi-Provider Gateway Failover   │   │
│   │   3. Double-Entry Accounting Ledger        4. Automated Two-Way Reconciliation  │   │
│   │   5. T+1 Settlement & Fee Batcher          6. HMAC-SHA256 Webhook Dispatcher    │   │
│   │   7. SHA-256 Chained Immutable Audit Log   8. Heuristic Risk & Velocity Engine  │   │
│   └────────────────────────────────────────┬────────────────────────────────────────┘   │
│                                            ▼                                            │
│                 [PostgreSQL 16 Storage]          [Redis 7 Distributed Cache]            │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Core Technical Capabilities

### 🛡️ 1. Distributed Idempotency & Anti-Tampering Engine
- **Distributed Redis Mutex (`SET NX EX 10`)**: Prevents concurrent duplicate charge race conditions under high network concurrency.
- **Canonical Payload Hash Fingerprinting**: Computes a recursive SHA-256 digest of normalized payload parameters, rejecting altered replayed payloads with `HTTP 422 Unprocessable Entity`.
- **PCI-DSS Compliance**: Strips sensitive card CVV/CVC before cryptographic fingerprinting and database persistence.

### 💳 2. Gateway Simulation & Intelligent Failover
- **Deterministic Gateway Simulators**: Emulates response codes (`00` Approval, `51` Insufficient Funds, `33` Expired Card, `91` Provider Down, `504` Gateway Timeout).
- **Multi-Provider Failover**: Automatically cascades transactions across secondary providers upon upstream gateway timeouts or network partition failures.

### 📚 3. Double-Entry Accounting Ledger
- **Invariant Enforcement**: Every financial event strictly posts balanced journal entries satisfying $\sum \text{Debits} == \sum \text{Credits}$.
- **Zero-Drift Integrity Endpoint**: Real-time programmatic verification (`/api/v1/ledger/integrity`) ensuring the ledger maintains zero variance.
- **T+1 Settlement Processor**: Automates merchant gross-to-net fee deductions and generates bank wire disbursement batches.

### ⚖️ 4. Automated Two-Way Reconciliation
- Parses bank clearing files (CSV/JSON) and matches clearing records against platform ledger transactions.
- Categorizes discrepancies into `EXACT_MATCH`, `UNMATCHED_GATEWAY` (internal omission), and `AMOUNT_MISMATCH`.
- Automatically auto-captures orphaned `PENDING` transactions verified as captured upstream.

### 🔗 5. Cryptographic Chained Audit Trail
- Every administrative action generates an immutable audit entry containing `hash = SHA256(previous_hash + actor + action + payload + timestamp)`.
- Guarantees tamper-evidence: any modification to historic audit records breaks the mathematical cryptographic chain.

### 👥 6. Institutional Role-Based Access Control (RBAC)
- Multi-persona governance enforcing segregation of duties across financial operations:
  - **Platform Administrator (`admin@transactiq.io`)**: Full system configuration, merchant lifecycle management, system-wide overrides.
  - **Operations Lead (`ops@transactiq.io`)**: Transaction monitoring, gateway failover management, manual webhook replay, settlement triggers.
  - **Compliance Auditor (`auditor@transactiq.io`)**: Strict read-only governance, tamper-proof audit trail inspection, zero-drift ledger integrity verification.
  - **Merchant Operator (`admin@swiftpay.com`)**: Scoped exclusively to tenant transactions, API keys, and account settlements.

---

## 3. Technology Stack

- **Backend**: Laravel 11, PHP 8.4, Laravel Sanctum, Predis.
- **Frontend**: React 19, TypeScript 5.9, Vite 8, Lucide React, Tailwind CSS / Custom Design System.
- **Datastores**: PostgreSQL 16 (Relational & ACID Ledger), Redis 7 (Distributed Locks & Queues).
- **DevOps**: Docker, Docker Compose, Nginx, GitHub Actions CI/CD.

---

## 4. Quickstart with Docker

TransactIQ provides single-command container orchestration:

```bash
# 1. Clone the repository
git clone https://github.com/akinlabiashraf/TransactIQ.git
cd TransactIQ

# 2. Copy Docker environment file
cp .env.docker.example .env

# 3. Launch the complete 6-container stack
docker compose up -d

# 4. Run database migrations and seeders
docker compose exec backend php artisan migrate --seed

# 5. Access the platforms:
# Frontend Dashboard: http://localhost:5173
# Backend REST API:   http://localhost:8000/api/v1/health
```

---

## 5. Automated Test Suite

TransactIQ includes an exhaustive end-to-end automated test suite covering authentication, RBAC authorization, transaction state transitions, idempotency locking, gateway failover, webhook HMAC dispatching, and ledger balance invariants:

```bash
cd backend
php artisan test
```

```text
Tests:    60 passed (407 assertions)
Duration: 68.47s
Result:   100% Green
```

---

## 6. Repository Structure

```text
TransactIQ/
├── backend/                  # Laravel 11 REST API & Financial Domain Engines
│   ├── app/
│   │   ├── Http/Controllers/ # REST Controllers (Auth, Payments, Ledger, Reconciliation)
│   │   ├── Http/Middleware/  # ValidateMerchantApiKey, CheckRole, Sanctum Auth
│   │   ├── Services/         # Idempotency, GatewayManager, Ledger, Reconcile, Audit
│   │   └── Models/           # Tenant, Transaction, LedgerEntry, WebhookEvent, AuditLog
│   ├── database/             # 19 Relational Migrations & Seeders
│   └── tests/Feature/        # 60 End-to-End Automated Feature Tests
├── frontend/                 # React 19 + TypeScript Operations Portal
│   ├── src/
│   │   ├── components/       # MetricCard, StatusBadge, Header, Sidebar
│   │   ├── views/            # Overview, Transactions, Ledger, Reconciliation, Webhooks
│   │   └── services/         # Typed API Client & Auth Switcher
├── docker/                   # Nginx & PHP Production Configuration
├── docs/                     # Guides (DOCKER_GUIDE.md, Architecture Specs)
├── docker-compose.yml        # Multi-Container Compose Orchestration
└── transactiq_detailed_stage_pathway.md # Master Stage Roadmap
```

---

## 7. License

Proprietary Financial Infrastructure — Engineered by [Ashraf Akinlabi](https://github.com/akinlabiashraf).
