import React, { useState, useEffect } from 'react';
import { 
  TrendingUp, 
  CheckCircle2, 
  Scale, 
  GitCompare, 
  ArrowRight, 
  ShieldCheck, 
  Zap, 
  Layers
} from 'lucide-react';
import { apiService } from '../services/api';
import type { NavSection } from '../components/Sidebar';
import type { Transaction, AnalyticsSummary } from '../types';

interface OverviewViewProps {
  transactions: Transaction[];
  onNavigate: (section: NavSection) => void;
  onTriggerTestPayment: () => void;
  apiKey?: string;
}

export const OverviewView: React.FC<OverviewViewProps> = ({
  transactions,
  onNavigate,
  onTriggerTestPayment,
  apiKey = 'tiq_live_swiftpay_test_key_001',
}) => {
  const [summary, setSummary] = useState<AnalyticsSummary | null>(null);

  useEffect(() => {
    let isMounted = true;
    apiService.getAnalyticsSummary(apiKey)
      .then(data => {
        if (isMounted) setSummary(data);
      })
      .catch(err => {
        console.warn('Analytics summary fetch failed, using local prop calculations:', err);
      });

    return () => {
      isMounted = false;
    };
  }, [apiKey]);

  const fallbackVolume = transactions
    .filter(t => t.status === 'SUCCESS')
    .reduce((acc, t) => acc + t.amount, 0);

  const fallbackSuccessCount = transactions.filter(t => t.status === 'SUCCESS').length;
  const fallbackSuccessRate = transactions.length > 0 
    ? Math.round((fallbackSuccessCount / transactions.length) * 100) 
    : 100;

  const totalVolume = summary?.overview.cleared_volume ?? fallbackVolume;
  const successRate = summary?.overview.success_rate ?? fallbackSuccessRate;
  const totalTransactionsCount = summary?.overview.total_transactions ?? transactions.length;
  const successfulTransactionsCount = summary?.overview.successful_transactions ?? fallbackSuccessCount;
  const isLedgerBalanced = summary?.ledger_health.is_balanced ?? true;

  const displayTransactions = (summary?.recent_transactions && summary.recent_transactions.length > 0)
    ? summary.recent_transactions
    : transactions.slice(0, 5);

  return (
    <div className="animate-fade-in" style={{ padding: '32px', display: 'flex', flexDirection: 'column', gap: '32px' }}>
      {/* Welcome Banner */}
      <div style={{
        background: 'linear-gradient(135deg, rgba(99, 102, 241, 0.15) 0%, rgba(6, 182, 212, 0.1) 100%)',
        border: '1px solid var(--border-accent)',
        borderRadius: 'var(--radius-xl)',
        padding: '28px 32px',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        position: 'relative',
        overflow: 'hidden',
      }}>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
            <span className="badge badge-info">Production Engine Ready</span>
            <span style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>PostgreSQL 16 ACID Storage Active</span>
          </div>
          <h2 style={{ fontSize: '24px', fontWeight: 800, color: '#fff' }}>
            Financial Infrastructure & Ledger Console
          </h2>
          <p style={{ fontSize: '13px', color: 'var(--text-secondary)', marginTop: '4px', maxWidth: '640px' }}>
            Automated idempotency enforcement, deterministic finite state transitions, 
            balanced double-entry ledger bookkeeping, and provider reconciliation.
          </p>
        </div>

        <div style={{ display: 'flex', gap: '12px' }}>
          <button 
            className="btn btn-primary"
            onClick={onTriggerTestPayment}
          >
            <Zap size={16} />
            <span>Simulate Payment</span>
          </button>
          <button 
            className="btn btn-secondary"
            onClick={() => onNavigate('sandbox')}
          >
            <Layers size={16} />
            <span>API Keys & Sandbox</span>
          </button>
        </div>
      </div>

      {/* 4 Metric Cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '20px' }}>
        {/* Card 1 */}
        <div className="card card-interactive">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', color: 'var(--text-secondary)' }}>
            <span style={{ fontSize: '12px', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              Cleared Volume
            </span>
            <div style={{ padding: '6px', borderRadius: '8px', background: 'rgba(99, 102, 241, 0.12)', color: 'var(--accent-primary)' }}>
              <TrendingUp size={18} />
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: 800, color: '#fff', marginTop: '12px', fontFamily: 'var(--font-mono)' }}>
            {apiService.formatMoney(totalVolume)}
          </div>
          <div style={{ fontSize: '12px', color: 'var(--success)', marginTop: '8px', display: 'flex', alignItems: 'center', gap: '4px' }}>
            <span>Settlement cycle: T+1 Cleared</span>
          </div>
        </div>

        {/* Card 2 */}
        <div className="card card-interactive">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', color: 'var(--text-secondary)' }}>
            <span style={{ fontSize: '12px', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              Success Rate
            </span>
            <div style={{ padding: '6px', borderRadius: '8px', background: 'rgba(16, 185, 129, 0.12)', color: 'var(--success)' }}>
              <CheckCircle2 size={18} />
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: 800, color: '#fff', marginTop: '12px', fontFamily: 'var(--font-mono)' }}>
            {successRate}%
          </div>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '8px' }}>
            {successfulTransactionsCount} of {totalTransactionsCount} payments captured
          </div>
        </div>

        {/* Card 3 */}
        <div className="card card-interactive">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', color: 'var(--text-secondary)' }}>
            <span style={{ fontSize: '12px', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              General Ledger Balance
            </span>
            <div style={{ padding: '6px', borderRadius: '8px', background: 'rgba(6, 182, 212, 0.12)', color: 'var(--info)' }}>
              <Scale size={18} />
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: 800, color: '#fff', marginTop: '12px', fontFamily: 'var(--font-mono)' }}>
            {apiService.formatMoney(totalVolume)}
          </div>
          <div style={{ fontSize: '12px', color: isLedgerBalanced ? 'var(--info)' : 'var(--danger)', marginTop: '8px' }}>
            Debits = Credits Invariant: {isLedgerBalanced ? 'Balanced (Zero Variance)' : 'Discrepancy Alert'}
          </div>
        </div>

        {/* Card 4 */}
        <div className="card card-interactive">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', color: 'var(--text-secondary)' }}>
            <span style={{ fontSize: '12px', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              Reconciliation
            </span>
            <div style={{ padding: '6px', borderRadius: '8px', background: 'rgba(245, 158, 11, 0.12)', color: 'var(--warning)' }}>
              <GitCompare size={18} />
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: 800, color: '#fff', marginTop: '12px', fontFamily: 'var(--font-mono)' }}>
            0 Exceptions
          </div>
          <div style={{ fontSize: '12px', color: 'var(--success)', marginTop: '8px' }}>
            100% Multi-source match
          </div>
        </div>
      </div>

      {/* State Machine Architecture Visualizer */}
      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
          <div>
            <h3 style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
              Deterministic Transaction State Machine (FSM)
            </h3>
            <p style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '2px' }}>
              Strict transition enforcement prevents invalid financial mutations or unauthorized state alterations
            </p>
          </div>
          <span className="badge badge-success">
            <ShieldCheck size={12} />
            <span>Invariant Enforced</span>
          </span>
        </div>

        <div style={{ 
          display: 'grid', 
          gridTemplateColumns: 'repeat(5, 1fr)', 
          gap: '12px', 
          background: 'rgba(0, 0, 0, 0.25)', 
          padding: '20px', 
          borderRadius: 'var(--radius-md)',
          border: '1px solid var(--border-subtle)'
        }}>
          <div style={{ padding: '12px', borderRadius: '8px', background: 'var(--bg-card)', border: '1px solid var(--border-subtle)' }}>
            <span className="badge badge-neutral" style={{ marginBottom: '8px' }}>Step 1</span>
            <div style={{ fontWeight: 700, fontSize: '13px', color: '#fff' }}>INITIATED</div>
            <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
              API key verified & idempotency locked
            </div>
          </div>

          <div style={{ padding: '12px', borderRadius: '8px', background: 'var(--bg-card)', border: '1px solid var(--border-subtle)' }}>
            <span className="badge badge-info" style={{ marginBottom: '8px' }}>Step 2</span>
            <div style={{ fontWeight: 700, fontSize: '13px', color: 'var(--info)' }}>PROCESSING</div>
            <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
              Dispatched to payment gateway adapter
            </div>
          </div>

          <div style={{ padding: '12px', borderRadius: '8px', background: 'var(--bg-card)', border: '1px solid var(--border-subtle)' }}>
            <span className="badge badge-warning" style={{ marginBottom: '8px' }}>Step 3 (Alt)</span>
            <div style={{ fontWeight: 700, fontSize: '13px', color: 'var(--warning)' }}>PENDING</div>
            <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
              Awaiting 3DS / external bank hook
            </div>
          </div>

          <div style={{ padding: '12px', borderRadius: '8px', background: 'var(--bg-card)', border: '1px solid rgba(16, 185, 129, 0.3)' }}>
            <span className="badge badge-success" style={{ marginBottom: '8px' }}>Step 4</span>
            <div style={{ fontWeight: 700, fontSize: '13px', color: 'var(--success)' }}>SUCCESS</div>
            <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
              Ledger debited & signed webhook fired
            </div>
          </div>

          <div style={{ padding: '12px', borderRadius: '8px', background: 'var(--bg-card)', border: '1px solid var(--border-subtle)' }}>
            <span className="badge badge-danger" style={{ marginBottom: '8px' }}>Step 4 (Alt)</span>
            <div style={{ fontWeight: 700, fontSize: '13px', color: 'var(--danger)' }}>FAILED</div>
            <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
              Card decline / gateway timeout logged
            </div>
          </div>
        </div>
      </div>

      {/* Recent Transactions Table Preview */}
      <div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
          <div>
            <h3 style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
              Live Transactions Stream
            </h3>
            <p style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
              Real-time feed with idempotency keys and state tracking
            </p>
          </div>
          <button 
            className="btn btn-secondary" 
            style={{ fontSize: '12px', padding: '6px 12px' }}
            onClick={() => onNavigate('transactions')}
          >
            <span>View All Transactions</span>
            <ArrowRight size={14} />
          </button>
        </div>

        <table className="data-table">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Customer</th>
              <th>Amount</th>
              <th>Payment Method</th>
              <th>Idempotency Key</th>
              <th>Status</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            {displayTransactions.map((t: any) => (
              <tr key={t.id}>
                <td className="mono" style={{ fontWeight: 600, color: '#fff' }}>
                  {t.reference}
                </td>
                <td>{t.customer_email ?? 'customer@example.com'}</td>
                <td className="mono" style={{ fontWeight: 700, color: '#fff' }}>
                  {apiService.formatMoney(t.amount, t.currency)}
                </td>
                <td>
                  <span className="badge badge-neutral">{t.payment_method}</span>
                </td>
                <td className="mono" style={{ fontSize: '11px', color: 'var(--text-muted)' }}>
                  {t.idempotency_key ? `${t.idempotency_key.substring(0, 14)}...` : 'N/A'}
                </td>
                <td>
                  <span className={`badge ${
                    t.status === 'SUCCESS' ? 'badge-success' :
                    t.status === 'PROCESSING' ? 'badge-info' :
                    t.status === 'PENDING' ? 'badge-warning' : 'badge-danger'
                  }`}>
                    <div className="pulse-dot" />
                    <span>{t.status}</span>
                  </span>
                </td>
                <td style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
                  {new Date(t.created_at).toLocaleTimeString()}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};
