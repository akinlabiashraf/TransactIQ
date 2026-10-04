/**
 * TransactIQ — High-Concurrency Distributed Stress Runner (Node.js)
 *
 * Runs concurrent parallel requests against the running TransactIQ backend
 * using native fetch and Promise.all to stress-test idempotency locks,
 * compute latency percentiles (p50, p90, p95, p99), and verify zero duplicate transactions.
 */

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8000';
const API_KEY = process.env.API_KEY || 'tiq_test_sec_swiftpay_stress_key_000000000000';

function calculatePercentile(values, p) {
    if (values.length === 0) return 0;
    values.sort((a, b) => a - b);
    const index = Math.ceil((p / 100) * values.length) - 1;
    return values[Math.max(0, index)];
}

async function runIdempotencyBurstTest(concurrency = 50) {
    console.log(`\n=============================================================`);
    console.log(`TRANSACTIQ — CONCURRENT IDEMPOTENCY BURST TEST (${concurrency} PARALLEL)`);
    console.log(`=============================================================`);

    const sharedKey = `burst_node_${Date.now()}_${Math.random().toString(36).substring(2, 8)}`;
    const payload = {
        amount: 250000, // ₦2,500.00
        currency: 'NGN',
        payment_method: 'CARD',
        customer: {
            email: 'burst_tester@example.com',
            name: 'Burst Concurrency User',
        },
        card: {
            number: '4000000000000001',
            exp_month: '12',
            exp_year: '2028',
            cvv: '123',
        },
        metadata: {
            runner: 'node_concurrency_runner',
            shared_key: sharedKey,
        },
    };

    console.log(`Target Base URL:       ${BASE_URL}`);
    console.log(`Shared Idempotency Key: ${sharedKey}`);
    console.log(`Firing ${concurrency} simultaneous asynchronous HTTP requests...`);

    const startTotal = Date.now();
    const latencies = [];

    const promises = Array.from({ length: concurrency }).map(async (_, idx) => {
        const t0 = Date.now();
        try {
            const res = await fetch(`${BASE_URL}/api/v1/payments`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Api-Key': API_KEY,
                    'Idempotency-Key': sharedKey,
                    'X-Stress-Test': 'true',
                },
                body: JSON.stringify(payload),
            });
            const duration = Date.now() - t0;
            latencies.push(duration);
            const body = await res.json().catch(() => ({}));
            return {
                index: idx,
                status: res.status,
                isReplay: res.headers.get('x-idempotent-replay') === 'true',
                reference: body?.data?.reference || null,
                body,
            };
        } catch (err) {
            const duration = Date.now() - t0;
            latencies.push(duration);
            return {
                index: idx,
                status: 500,
                error: err.message,
            };
        }
    });

    const results = await Promise.all(promises);
    const totalDuration = Date.now() - startTotal;

    const successful = results.filter((r) => r.status === 200 || r.status === 201);
    const created = results.filter((r) => r.status === 201);
    const replayed = results.filter((r) => r.status === 200);
    const conflicts = results.filter((r) => r.status === 409);
    const errors = results.filter((r) => r.status >= 500);

    const references = new Set(results.map((r) => r.reference).filter(Boolean));

    console.log(`\nResults:`);
    console.log(` • Total Requests:       ${concurrency}`);
    console.log(` • Successful (200/201): ${successful.length}`);
    console.log(`     - Initial Created (201):  ${created.length}`);
    console.log(`     - Idempotent Replayed (200): ${replayed.length}`);
    console.log(` • Lock Conflicts (409): ${conflicts.length}`);
    console.log(` • Server Errors (5xx):  ${errors.length}`);
    console.log(` • Unique References:    ${references.size} (Expected strictly: 1)`);
    console.log(`\nLatency Statistics:`);
    console.log(` • Total Wall Clock Time: ${totalDuration}ms`);
    console.log(` • p50 (Median):         ${calculatePercentile(latencies, 50)}ms`);
    console.log(` • p90:                  ${calculatePercentile(latencies, 90)}ms`);
    console.log(` • p95:                  ${calculatePercentile(latencies, 95)}ms`);
    console.log(` • p99:                  ${calculatePercentile(latencies, 99)}ms`);

    if (references.size === 1 && errors.length === 0) {
        console.log(`\n✓ [PASS] Idempotency locking invariant held perfectly: strictly 1 transaction created!`);
        return true;
    } else {
        console.log(`\n✗ [FAIL] Idempotency anomaly detected!`);
        return false;
    }
}

// Run test if invoked directly
runIdempotencyBurstTest(50)
    .then((passed) => process.exit(passed ? 0 : 1))
    .catch((err) => {
        console.error(err);
        process.exit(1);
    });
