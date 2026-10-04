# TransactIQ — Load & Concurrency Stress Testing Suite (Stage 17)

This directory contains the production-grade load, throughput, and distributed concurrency stress-testing suite for **TransactIQ**, engineered with **Grafana k6** and **Node.js**.

---

## 1. Concurrency Architecture & Financial Invariants

In institutional digital payments, high concurrency presents catastrophic failure risks if not handled defensively:
- **Duplicate Charges / Double Debits**: When mobile apps double-tap or networks retry identical requests in parallel.
- **Race Conditions in State Machine**: Ambiguous transaction transitions when multiple threads attempt to update status simultaneously.
- **Ledger Invariance Drift**: When concurrent debits and credits get out of sync, leading to accounting balance sheet discrepancies.

TransactIQ defends against these with 4 layers of architectural protection:
1. **Redis Distributed Mutex Locking**: Atomically acquires a scoped mutex lock (`idemp_lock:{merchant_id}:{idempotency_key}`) before processing begins.
2. **Double-Checked Locking Pattern**: Re-checks database transaction existence *inside* the critical lock boundary.
3. **Payload Checksum Verification (SHA-256)**: Ensures clients cannot reuse an idempotency key with tampered parameters (amounts/currencies).
4. **Atomic Database Transactions with Pessimistic Locking**: `lockForUpdate()` on ledger accounts and double-entry balance updates.

---

## 2. Test Scripts Inventory

| Script | Purpose | Concurrency / VUs | Target Thresholds |
| :--- | :--- | :---: | :--- |
| [`idempotency_stress.js`](./idempotency_stress.js) | Sends a burst of 100 concurrent requests with the **identical** `Idempotency-Key` | 20 VUs (burst) | `rate > 0.99`, `p95 < 1500ms`, strictly 1 DB transaction |
| [`throughput_benchmark.js`](./throughput_benchmark.js) | Ramps up from 1 to 50 Virtual Users to benchmark sustained payment throughput | 1 ➔ 25 ➔ 50 VUs | `p50 < 300ms`, `p95 < 1000ms`, error rate `< 1%` |
| [`ledger_integrity_stress.js`](./ledger_integrity_stress.js) | Dispatches 50 concurrent transactions with varying amounts and verifies zero ledger drift | 15 VUs | `SUM(Debits) === SUM(Credits)`, variance = 0 |
| [`concurrency_runner.mjs`](../stress/concurrency_runner.mjs) | Standalone Node.js parallel runner for machines without native k6 installed | 50 parallel requests | Zero duplicate records, p50/p95/p99 breakdown |

---

## 3. How to Execute the Stress Tests

### Prerequisites
Make sure the TransactIQ backend server is running:
```bash
# In backend/
php artisan serve
```

---

### Option A: Run via Grafana k6 (Recommended)

1. **Idempotency Burst Stress Test (100 Requests)**:
   ```bash
   k6 run tests/k6/idempotency_stress.js
   ```

2. **Throughput & Ramp-Up Benchmark (Up to 50 VUs)**:
   ```bash
   k6 run tests/k6/throughput_benchmark.js
   ```

3. **Concurrent Ledger Posting & Invariant Validation**:
   ```bash
   k6 run tests/k6/ledger_integrity_stress.js
   ```

4. **Passing Custom Host / API Key via Environment Variables**:
   ```bash
   k6 run -e BASE_URL=http://localhost:8000 -e API_KEY=your_key tests/k6/idempotency_stress.js
   ```

---

### Option B: Run via Standalone Node.js Runner (Zero Install Required)

Any developer with Node.js 18+ can run:
```bash
node tests/stress/concurrency_runner.mjs
```

---

### Option C: Run via Docker (Cloud / CI Ready)

```bash
docker run --rm -i --network=host grafana/k6 run - < tests/k6/idempotency_stress.js
```

---

## 4. Double-Entry Ledger Verification Command

After running any high-volume concurrency benchmark, execute the built-in Artisan ledger audit command to mathematically confirm zero variance drift:

```bash
cd backend
php artisan ledger:verify
```

Expected Output:
```
====================================================================
            TRANSACTIQ — DOUBLE-ENTRY LEDGER INTEGRITY AUDIT        
====================================================================

+-----------------------------+-------------------------------+
| Audit Metric                | Value                         |
+-----------------------------+-------------------------------+
| Currency Scope              | ALL CURRENCIES                |
| Total Journal Entries       | 120                           |
| Total Debits (Minor Units)  | ₦1,250,000.00                 |
| Total Credits (Minor Units) | ₦1,250,000.00                 |
| Discrepancy / Variance      | 0 (ZERO DRIFT)                |
| Invariant Status            | BALANCED (DEBITS === CREDITS) |
+-----------------------------+-------------------------------+

✓ [SUCCESS] Mathematical invariance verified across all financial ledger journals.
```

---

## 5. Automated Regression Test in CI/CD

TransactIQ includes automated test suites in PHPUnit that validate idempotency locking and ledger integrity in every GitHub Actions run:

```bash
php artisan test --filter=ConcurrencyAndStressTest
```
