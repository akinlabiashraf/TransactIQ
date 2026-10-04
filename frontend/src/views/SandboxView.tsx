import React, { useState } from 'react';
import { 
  KeyRound, 
  Copy, 
  Check, 
  Send, 
  RefreshCw,
  Terminal,
  Zap,
  CreditCard,
  CheckCircle2,
  XCircle,
  Clock
} from 'lucide-react';
import type { ApiKeyItem, Transaction, LedgerEntry, WebhookItem, PaymentAttemptItem } from '../types';
import { apiService } from '../services/api';

interface SandboxViewProps {
  apiKeys: ApiKeyItem[];
  onPaymentSimulated: (
    newTxn: Transaction, 
    newEntries: LedgerEntry[], 
    newWebhook: WebhookItem
  ) => void;
}

interface TestCardPreset {
  id: string;
  name: string;
  number: string;
  expMonth: string;
  expYear: string;
  cvv: string;
  expectedCode: string;
  expectedOutcome: 'SUCCESS' | 'FAILED' | 'PENDING';
  badgeClass: string;
  description: string;
}

const TEST_CARDS: TestCardPreset[] = [
  {
    id: 'approved_00',
    name: 'Instant Approved (00)',
    number: '4000 0000 0000 0001',
    expMonth: '12',
    expYear: '2028',
    cvv: '123',
    expectedCode: '00 (Approved)',
    expectedOutcome: 'SUCCESS',
    badgeClass: 'badge-success',
    description: 'Standard successful charge. Approves immediately with single gateway attempt.',
  },
  {
    id: 'declined_51',
    name: 'Insufficient Funds (51)',
    number: '4000 0000 0000 0051',
    expMonth: '08',
    expYear: '2027',
    cvv: '456',
    expectedCode: '51 (Decline)',
    expectedOutcome: 'FAILED',
    badgeClass: 'badge-danger',
    description: 'Customer account lacks sufficient balance. Non-retryable terminal decline.',
  },
  {
    id: 'expired_33',
    name: 'Card Expired (33)',
    number: '4000 0000 0000 0033',
    expMonth: '01',
    expYear: '2022',
    cvv: '789',
    expectedCode: '33 (Expired)',
    expectedOutcome: 'FAILED',
    badgeClass: 'badge-danger',
    description: 'Card past its validity date. Gateway rejects immediately.',
  },
  {
    id: 'failover_91',
    name: 'Issuer Down & Failover Retry (91)',
    number: '4000 0000 0000 0091',
    expMonth: '05',
    expYear: '2029',
    cvv: '999',
    expectedCode: '91 &rarr; 00 Failover',
    expectedOutcome: 'SUCCESS',
    badgeClass: 'badge-warning',
    description: 'Primary switch fails with Code 91. GatewayManager automatically retries on Secondary Fallback switch.',
  },
  {
    id: 'otp_02',
    name: '3D Secure OTP Required (02)',
    number: '4000 0000 0000 0002',
    expMonth: '10',
    expYear: '2027',
    cvv: '321',
    expectedCode: '02 (Auth Required)',
    expectedOutcome: 'PENDING',
    badgeClass: 'badge-info',
    description: 'Issuing bank requests 3DS challenge. Transaction placed in PENDING state awaiting verification.',
  },
  {
    id: 'timeout_504',
    name: 'Gateway Timeout (504)',
    number: '4000 0000 0000 0504',
    expMonth: '03',
    expYear: '2026',
    cvv: '504',
    expectedCode: '504 (Timeout)',
    expectedOutcome: 'PENDING',
    badgeClass: 'badge-warning',
    description: 'Network gateway times out awaiting issuer. State held in PENDING for asynchronous polling/reconciliation.',
  },
];

export const SandboxView: React.FC<SandboxViewProps> = ({ apiKeys, onPaymentSimulated }) => {
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  // Selected Preset or Custom Card
  const [selectedPresetId, setSelectedPresetId] = useState<string>(TEST_CARDS[0].id);
  const [cardNumber, setCardNumber] = useState<string>(TEST_CARDS[0].number);
  const [cardExp, setCardExp] = useState<string>(`${TEST_CARDS[0].expMonth}/${TEST_CARDS[0].expYear.slice(-2)}`);
  const [cardCvv, setCardCvv] = useState<string>(TEST_CARDS[0].cvv);

  // Simulator Form State
  const [amountNaira, setAmountNaira] = useState('25000');
  const [customerEmail, setCustomerEmail] = useState('customer@example.com');
  const [idempotencyKey, setIdempotencyKey] = useState(`idemp-${Math.random().toString(36).substring(2, 12)}`);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [simulationLog, setSimulationLog] = useState<string[]>([]);
  const [idempotencyHits, setIdempotencyHits] = useState<Record<string, Transaction>>({});
  const [latestAttempts, setLatestAttempts] = useState<PaymentAttemptItem[]>([]);
  const [latestStatus, setLatestStatus] = useState<string | null>(null);

  const activeApiKey = apiKeys.find(k => k.type === 'TEST')?.public_key || apiKeys[0]?.public_key || 'tiq_test_pub_swiftpay_demo_key';

  const handleCopy = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopiedKey(text);
    setTimeout(() => setCopiedKey(null), 2000);
  };

  const handleGenerateNewKey = () => {
    setIdempotencyKey(`idemp-${Math.random().toString(36).substring(2, 12)}`);
  };

  const handleSelectPreset = (preset: TestCardPreset) => {
    setSelectedPresetId(preset.id);
    setCardNumber(preset.number);
    setCardExp(`${preset.expMonth}/${preset.expYear.slice(-2)}`);
    setCardCvv(preset.cvv);
  };

  const handleSimulatePayment = async () => {
    setIsSubmitting(true);
    setSimulationLog([]);
    setLatestAttempts([]);
    setLatestStatus(null);

    const log = (msg: string) => {
      setSimulationLog(prev => [...prev, `[${new Date().toLocaleTimeString()}] ${msg}`]);
    };

    log(`1. Authenticating merchant API request with key [${activeApiKey.slice(0, 16)}...]`);
    await new Promise(r => setTimeout(r, 120));

    // Check frontend cache for duplicate idempotency key
    if (idempotencyHits[idempotencyKey]) {
      const cached = idempotencyHits[idempotencyKey];
      log(`⚠️ IDEMPOTENCY LOCK TRIGGERED: Key '${idempotencyKey}' was already processed!`);
      log(`✔ Returning cached transaction '${cached.reference}' without re-charging customer or duplicating ledger entries!`);
      setLatestStatus(cached.status);
      setLatestAttempts(cached.payment_attempts || []);
      setIsSubmitting(false);
      return;
    }

    log(`2. Idempotency check PASSED. State: INITIATED`);
    await new Promise(r => setTimeout(r, 150));

    const amountMinor = Math.round(parseFloat(amountNaira || '0') * 100);
    const expParts = cardExp.split('/');
    const expMonth = expParts[0] || '12';
    const expYear = expParts[1] ? (expParts[1].length === 2 ? `20${expParts[1]}` : expParts[1]) : '2028';

    log(`3. Dispatching to Gateway Manager... Primary Provider: SIMULATED_PRIMARY`);
    log(`   • Card PAN: ${cardNumber.slice(0, 4)} •••• •••• ${cardNumber.slice(-4)}`);

    try {
      // Execute through live backend API if available
      const payload = {
        amount: amountMinor,
        currency: 'NGN',
        payment_method: 'CARD',
        customer: { email: customerEmail, name: 'Demo Customer' },
        card: {
          number: cardNumber,
          exp_month: expMonth,
          exp_year: expYear,
          cvv: cardCvv,
        },
      };

      const res = await apiService.processPayment(activeApiKey, idempotencyKey, payload);

      if (res && res.data) {
        const txn: Transaction = res.data;
        const attempts: PaymentAttemptItem[] = res.data.payment_attempts || [];
        setLatestAttempts(attempts);
        setLatestStatus(txn.status);

        if (res.replayed) {
          log(`⚠️ Server reported IDEMPOTENT REPLAY header: Returned existing record.`);
        }

        attempts.forEach((att, idx) => {
          log(`Attempt #${idx + 1} (${att.provider}): Status [${att.status}], Code: [${att.error_code || '00'}], Latency: ${att.latency_ms}ms`);
          if (att.error_message) {
            log(`   ↳ Gateway Note: ${att.error_message}`);
          }
        });

        if (attempts.length > 1) {
          log(`🔄 MULTI-GATEWAY FAILOVER: Primary switch failed; successfully recovered via ${attempts[1].provider}!`);
        }

        log(`4. State Machine Transitioned to: ${txn.status}`);

        // Double-entry ledger calculations
        const feeMinor = txn.fee_amount || Math.round(amountMinor * 0.015);
        const netMinor = txn.net_amount || (amountMinor - feeMinor);

        const newEntries: LedgerEntry[] = txn.status === 'SUCCESS' ? [
          {
            id: crypto.randomUUID(),
            reference: `JRN-${Math.floor(100000 + Math.random() * 900000)}`,
            entry_type: 'PAYMENT_CAPTURED',
            debit_account: 'ACC-PROVIDER-CLEARING',
            credit_account: 'ACC-SWIFT-AVAIL',
            amount: netMinor,
            currency: 'NGN',
            description: `Captured payment for ${txn.reference}`,
            created_at: new Date().toISOString(),
          },
          {
            id: crypto.randomUUID(),
            reference: `JRN-${Math.floor(100000 + Math.random() * 900000)}`,
            entry_type: 'PLATFORM_FEE',
            debit_account: 'ACC-PROVIDER-CLEARING',
            credit_account: 'ACC-PLATFORM-REVENUE',
            amount: feeMinor,
            currency: 'NGN',
            description: `Platform service fee for ${txn.reference}`,
            created_at: new Date().toISOString(),
          }
        ] : [];

        const newWebhook: WebhookItem = {
          id: crypto.randomUUID(),
          event_type: txn.status === 'SUCCESS' ? 'payment.success' : 'payment.failed',
          endpoint_url: 'https://webhook.site/test-transactiq',
          signature: 'sha256=' + Array.from({length: 32}, () => Math.floor(Math.random()*16).toString(16)).join(''),
          attempts: 1,
          max_attempts: 5,
          status: 'DELIVERED',
          response_status: 200,
          created_at: new Date().toISOString(),
        };

        if (txn.status === 'SUCCESS') {
          log(`5. Double-entry ledger recording:`);
          log(`   • DEBIT: Provider Clearing Account (₦${(amountMinor/100).toFixed(2)})`);
          log(`   • CREDIT: SwiftPay Available Balance (₦${(netMinor/100).toFixed(2)})`);
          log(`   • CREDIT: Platform Revenue Account (₦${(feeMinor/100).toFixed(2)})`);
        }

        log(`6. HMAC-SHA256 Signed Webhook dispatched: '${newWebhook.event_type}'`);

        setIdempotencyHits(prev => ({ ...prev, [idempotencyKey]: txn }));
        onPaymentSimulated(txn, newEntries, newWebhook);
        setIsSubmitting(false);
        return;
      }
    } catch (err) {
      log(`⚠️ Live API fallback: Simulating client-side gateway response...`);
    }

    // Client-side fallback simulation in case backend dev server is not actively connected
    const matchedPreset = TEST_CARDS.find(p => p.number.replace(/\s+/g, '') === cardNumber.replace(/\s+/g, '')) || TEST_CARDS[0];
    const outcome = matchedPreset.expectedOutcome;
    const ref = `TXN-${new Date().toISOString().slice(0, 10).replace(/-/g, '')}-${Math.floor(100000 + Math.random() * 900000)}`;
    const feeMinor = Math.round(amountMinor * 0.015);
    const netMinor = amountMinor - feeMinor;

    const mockAttempts: PaymentAttemptItem[] = [];
    if (matchedPreset.id === 'failover_91') {
      mockAttempts.push({
        attempt_number: 1,
        provider: 'SIMULATED_PRIMARY',
        status: 'FAILED',
        error_code: 'BANK_ISSUER_DOWN_91',
        error_message: 'Issuing bank network switch is unresponsive (Code 91).',
        latency_ms: 195,
        created_at: new Date().toISOString(),
      });
      mockAttempts.push({
        attempt_number: 2,
        provider: 'SIMULATED_FALLBACK',
        status: 'SUCCESS',
        error_code: '00',
        error_message: 'Approved via secondary interbank switch route.',
        latency_ms: 110,
        created_at: new Date().toISOString(),
      });
      log(`Attempt #1 (SIMULATED_PRIMARY): FAILED (Code 91 - Bank Switch Unavailable) [195ms]`);
      log(`🔄 MULTI-GATEWAY FAILOVER: Automatically re-routed to SIMULATED_FALLBACK`);
      log(`Attempt #2 (SIMULATED_FALLBACK): SUCCESS (Approved 00) [110ms]`);
    } else {
      mockAttempts.push({
        attempt_number: 1,
        provider: 'SIMULATED_PRIMARY',
        status: outcome,
        error_code: matchedPreset.expectedCode,
        error_message: matchedPreset.description,
        latency_ms: Math.floor(60 + Math.random() * 60),
        created_at: new Date().toISOString(),
      });
      log(`Attempt #1 (SIMULATED_PRIMARY): ${outcome} (Code ${matchedPreset.expectedCode})`);
    }

    setLatestAttempts(mockAttempts);
    setLatestStatus(outcome);

    const newTxn: Transaction = {
      id: crypto.randomUUID(),
      reference: ref,
      merchant_id: '01a0c3e0-62cf-7148-92ac-96e816405f08',
      customer_email: customerEmail,
      amount: amountMinor,
      fee_amount: outcome === 'SUCCESS' ? feeMinor : 0,
      net_amount: outcome === 'SUCCESS' ? netMinor : 0,
      currency: 'NGN',
      status: outcome,
      payment_method: 'CARD',
      idempotency_key: idempotencyKey,
      provider: mockAttempts[mockAttempts.length - 1].provider,
      payment_attempts: mockAttempts,
      created_at: new Date().toISOString(),
    };

    const newEntries: LedgerEntry[] = outcome === 'SUCCESS' ? [
      {
        id: crypto.randomUUID(),
        reference: `JRN-${Math.floor(100000 + Math.random() * 900000)}`,
        entry_type: 'PAYMENT_CAPTURED',
        debit_account: 'ACC-PROVIDER-CLEARING',
        credit_account: 'ACC-SWIFT-AVAIL',
        amount: netMinor,
        currency: 'NGN',
        description: `Captured payment for ${ref}`,
        created_at: new Date().toISOString(),
      },
      {
        id: crypto.randomUUID(),
        reference: `JRN-${Math.floor(100000 + Math.random() * 900000)}`,
        entry_type: 'PLATFORM_FEE',
        debit_account: 'ACC-PROVIDER-CLEARING',
        credit_account: 'ACC-PLATFORM-REVENUE',
        amount: feeMinor,
        currency: 'NGN',
        description: `Platform service fee for ${ref}`,
        created_at: new Date().toISOString(),
      }
    ] : [];

    const newWebhook: WebhookItem = {
      id: crypto.randomUUID(),
      event_type: outcome === 'SUCCESS' ? 'payment.success' : 'payment.failed',
      endpoint_url: 'https://webhook.site/test-transactiq',
      signature: 'sha256=' + Array.from({length: 32}, () => Math.floor(Math.random()*16).toString(16)).join(''),
      attempts: 1,
      max_attempts: 5,
      status: 'DELIVERED',
      response_status: 200,
      created_at: new Date().toISOString(),
    };

    if (outcome === 'SUCCESS') {
      log(`4. Double-entry ledger recording:`);
      log(`   • DEBIT: Provider Clearing Account (₦${(amountMinor/100).toFixed(2)})`);
      log(`   • CREDIT: SwiftPay Available Balance (₦${(netMinor/100).toFixed(2)})`);
      log(`   • CREDIT: Platform Revenue Account (₦${(feeMinor/100).toFixed(2)})`);
    }

    log(`5. HMAC-SHA256 Signed Webhook dispatched: '${newWebhook.event_type}'`);

    setIdempotencyHits(prev => ({ ...prev, [idempotencyKey]: newTxn }));
    onPaymentSimulated(newTxn, newEntries, newWebhook);
    setIsSubmitting(false);
  };

  return (
    <div className="animate-fade-in" style={{ padding: '32px', display: 'flex', flexDirection: 'column', gap: '32px' }}>
      
      {/* API Keys Card */}
      <div className="card">
        <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '16px' }}>
          <KeyRound size={20} color="var(--accent-primary)" />
          <h3 style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
            Merchant API Credentials (SwiftPay Retail)
          </h3>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
          {apiKeys.map((key) => (
            <div 
              key={key.type}
              style={{
                background: 'rgba(0, 0, 0, 0.25)',
                border: '1px solid var(--border-subtle)',
                borderRadius: 'var(--radius-md)',
                padding: '16px',
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' }}>
                <span className={`badge ${key.type === 'LIVE' ? 'badge-success' : 'badge-info'}`}>
                  {key.type} KEY
                </span>
                <span className="mono" style={{ fontSize: '11px', color: 'var(--text-muted)' }}>
                  Secret: {key.secret_preview}
                </span>
              </div>

              <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginBottom: '4px' }}>
                Public API Key:
              </div>
              <div style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                background: 'var(--bg-app)',
                padding: '8px 12px',
                borderRadius: '6px',
                border: '1px solid var(--border-subtle)',
              }}>
                <span className="mono" style={{ fontSize: '12px', color: '#fff' }}>
                  {key.public_key}
                </span>
                <button
                  onClick={() => handleCopy(key.public_key)}
                  style={{ background: 'transparent', border: 'none', color: 'var(--text-muted)', cursor: 'pointer' }}
                >
                  {copiedKey === key.public_key ? <Check size={14} color="var(--success)" /> : <Copy size={14} />}
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Stage 6: Deterministic Test Card Presets Selector */}
      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <CreditCard size={18} color="var(--accent-primary)" />
            <h3 style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
              Deterministic Test Card Scenarios (Stage 6 Simulator)
            </h3>
          </div>
          <span className="badge badge-info">PLUGGABLE ADAPTER</span>
        </div>

        <p style={{ fontSize: '13px', color: 'var(--text-secondary)', marginBottom: '16px' }}>
          Select a deterministic test card to reproduce specific payment provider outcomes, network delays, or automated interbank failovers:
        </p>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '12px' }}>
          {TEST_CARDS.map((card) => {
            const isSelected = selectedPresetId === card.id;
            return (
              <div
                key={card.id}
                onClick={() => handleSelectPreset(card)}
                style={{
                  background: isSelected ? 'rgba(99, 102, 241, 0.12)' : 'var(--bg-app)',
                  border: isSelected ? '1px solid var(--accent-primary)' : '1px solid var(--border-subtle)',
                  borderRadius: 'var(--radius-md)',
                  padding: '14px',
                  cursor: 'pointer',
                  transition: 'all 0.15s ease',
                  position: 'relative',
                }}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                  <span style={{ fontSize: '13px', fontWeight: 700, color: isSelected ? '#fff' : 'var(--text-primary)' }}>
                    {card.name}
                  </span>
                  <span className={`badge ${card.badgeClass}`} style={{ fontSize: '10px' }}>
                    {card.expectedOutcome}
                  </span>
                </div>

                <div className="mono" style={{ fontSize: '12px', color: isSelected ? 'var(--accent-primary)' : 'var(--text-secondary)', marginBottom: '6px' }}>
                  {card.number}
                </div>

                <div style={{ fontSize: '11px', color: 'var(--text-muted)', lineHeight: 1.4 }}>
                  {card.description}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Simulator Execution Grid */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '24px' }}>
        {/* Left: Input Form */}
        <div className="card">
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '20px' }}>
            <Zap size={18} color="var(--warning)" />
            <h3 style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
              Execute Payment Charge
            </h3>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
            <div>
              <label style={{ fontSize: '12px', fontWeight: 600, color: 'var(--text-secondary)', display: 'block', marginBottom: '6px' }}>
                Amount (₦ NGN)
              </label>
              <input
                type="number"
                value={amountNaira}
                onChange={(e) => setAmountNaira(e.target.value)}
                style={{
                  width: '100%',
                  background: 'var(--bg-app)',
                  border: '1px solid var(--border-subtle)',
                  borderRadius: 'var(--radius-md)',
                  padding: '10px 14px',
                  color: '#fff',
                  fontFamily: 'var(--font-mono)',
                  fontSize: '15px',
                  fontWeight: 700,
                  outline: 'none',
                }}
              />
            </div>

            <div>
              <label style={{ fontSize: '12px', fontWeight: 600, color: 'var(--text-secondary)', display: 'block', marginBottom: '6px' }}>
                Customer Email
              </label>
              <input
                type="email"
                value={customerEmail}
                onChange={(e) => setCustomerEmail(e.target.value)}
                style={{
                  width: '100%',
                  background: 'var(--bg-app)',
                  border: '1px solid var(--border-subtle)',
                  borderRadius: 'var(--radius-md)',
                  padding: '10px 14px',
                  color: '#fff',
                  fontSize: '13px',
                  outline: 'none',
                }}
              />
            </div>

            {/* Card Information Fields */}
            <div style={{
              background: 'rgba(0, 0, 0, 0.25)',
              border: '1px solid var(--border-subtle)',
              borderRadius: 'var(--radius-md)',
              padding: '14px',
            }}>
              <div style={{ fontSize: '12px', fontWeight: 600, color: 'var(--text-secondary)', marginBottom: '10px', display: 'flex', alignItems: 'center', gap: '6px' }}>
                <CreditCard size={14} color="var(--accent-primary)" />
                <span>Card Details (Pre-filled from Preset)</span>
              </div>

              <div style={{ marginBottom: '10px' }}>
                <input
                  type="text"
                  value={cardNumber}
                  onChange={(e) => {
                    setCardNumber(e.target.value);
                    setSelectedPresetId('custom');
                  }}
                  placeholder="Card Number"
                  style={{
                    width: '100%',
                    background: 'var(--bg-app)',
                    border: '1px solid var(--border-subtle)',
                    borderRadius: 'var(--radius-md)',
                    padding: '8px 12px',
                    color: '#fff',
                    fontFamily: 'var(--font-mono)',
                    fontSize: '13px',
                    outline: 'none',
                  }}
                />
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                <input
                  type="text"
                  value={cardExp}
                  onChange={(e) => setCardExp(e.target.value)}
                  placeholder="MM/YY"
                  style={{
                    background: 'var(--bg-app)',
                    border: '1px solid var(--border-subtle)',
                    borderRadius: 'var(--radius-md)',
                    padding: '8px 12px',
                    color: '#fff',
                    fontFamily: 'var(--font-mono)',
                    fontSize: '13px',
                    outline: 'none',
                  }}
                />
                <input
                  type="text"
                  value={cardCvv}
                  onChange={(e) => setCardCvv(e.target.value)}
                  placeholder="CVV"
                  style={{
                    background: 'var(--bg-app)',
                    border: '1px solid var(--border-subtle)',
                    borderRadius: 'var(--radius-md)',
                    padding: '8px 12px',
                    color: '#fff',
                    fontFamily: 'var(--font-mono)',
                    fontSize: '13px',
                    outline: 'none',
                  }}
                />
              </div>
            </div>

            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                <label style={{ fontSize: '12px', fontWeight: 600, color: 'var(--text-secondary)' }}>
                  Idempotency Key (Uniqueness check)
                </label>
                <button
                  type="button"
                  onClick={handleGenerateNewKey}
                  style={{
                    background: 'transparent',
                    border: 'none',
                    color: 'var(--accent-primary)',
                    fontSize: '11px',
                    fontWeight: 600,
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '4px',
                  }}
                >
                  <RefreshCw size={11} />
                  <span>Generate New</span>
                </button>
              </div>
              <input
                type="text"
                value={idempotencyKey}
                onChange={(e) => setIdempotencyKey(e.target.value)}
                style={{
                  width: '100%',
                  background: 'var(--bg-app)',
                  border: '1px solid var(--border-subtle)',
                  borderRadius: 'var(--radius-md)',
                  padding: '10px 14px',
                  color: '#c7d2fe',
                  fontFamily: 'var(--font-mono)',
                  fontSize: '12px',
                  outline: 'none',
                }}
              />
              <p style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
                Tip: Keep the same key and click "Execute" again to test idempotency duplicate blocking!
              </p>
            </div>

            <button
              className="btn btn-primary"
              onClick={handleSimulatePayment}
              disabled={isSubmitting}
              style={{ marginTop: '8px', padding: '12px' }}
            >
              <Send size={15} />
              <span>{isSubmitting ? 'Dispatching to Gateway...' : 'Execute Payment Charge'}</span>
            </button>
          </div>
        </div>

        {/* Right: Real-Time Execution Log & Attempt Visualizer */}
        <div className="card" style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
          
          {/* Header */}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <Terminal size={18} color="var(--info)" />
              <h3 style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
                Gateway Telemetry & Attempts
              </h3>
            </div>
            {latestStatus && (
              <span className={`badge ${
                latestStatus === 'SUCCESS' ? 'badge-success' :
                latestStatus === 'PENDING' ? 'badge-info' : 'badge-danger'
              }`}>
                {latestStatus}
              </span>
            )}
          </div>

          {/* Attempts Visualizer Cards */}
          {latestAttempts.length > 0 && (
            <div style={{
              background: 'rgba(0,0,0,0.3)',
              borderRadius: 'var(--radius-md)',
              border: '1px solid var(--border-subtle)',
              padding: '12px',
              display: 'flex',
              flexDirection: 'column',
              gap: '8px'
            }}>
              <div style={{ fontSize: '11px', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase' }}>
                Recorded Attempts in `payment_attempts`
              </div>

              {latestAttempts.map((att, i) => (
                <div 
                  key={i} 
                  style={{
                    background: 'var(--bg-app)',
                    border: '1px solid var(--border-subtle)',
                    borderRadius: '6px',
                    padding: '10px 12px',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between'
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                    {att.status === 'SUCCESS' ? (
                      <CheckCircle2 size={16} color="var(--success)" />
                    ) : att.status === 'PENDING' ? (
                      <Clock size={16} color="var(--info)" />
                    ) : (
                      <XCircle size={16} color="var(--danger)" />
                    )}
                    <div>
                      <div style={{ fontSize: '12px', fontWeight: 700, color: '#fff' }}>
                        Attempt #{att.attempt_number}: {att.provider}
                      </div>
                      <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>
                        {att.error_code ? `Code: ${att.error_code}` : 'Code: 00 Approved'} 
                        {att.error_message && ` • ${att.error_message}`}
                      </div>
                    </div>
                  </div>

                  <span className="mono" style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>
                    {att.latency_ms} ms
                  </span>
                </div>
              ))}
            </div>
          )}

          {/* Console Log */}
          <div style={{
            flex: 1,
            background: '#04070c',
            border: '1px solid var(--border-subtle)',
            borderRadius: 'var(--radius-md)',
            padding: '16px',
            fontFamily: 'var(--font-mono)',
            fontSize: '12px',
            lineHeight: 1.6,
            color: '#a5b4fc',
            overflowY: 'auto',
            minHeight: '220px',
            maxHeight: '300px',
          }}>
            {simulationLog.length === 0 ? (
              <div style={{ color: 'var(--text-muted)', paddingTop: '40px', textAlign: 'center' }}>
                Click "Execute Payment Charge" to observe the full lifecycle:
                <br /><br />
                Idempotency Lock &rarr; Primary Switch &rarr; (Failover Retry) &rarr; State Machine &rarr; Ledger &rarr; Webhook
              </div>
            ) : (
              simulationLog.map((line, idx) => (
                <div key={idx} style={{ marginBottom: '4px' }}>
                  {line}
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
};
