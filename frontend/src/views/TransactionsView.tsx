import React, { useState } from 'react';
import { Search, ArrowLeftRight } from 'lucide-react';
import { apiService } from '../services/api';
import type { Transaction } from '../types';

interface TransactionsViewProps {
  transactions: Transaction[];
  onOpenSandbox: () => void;
}

export const TransactionsView: React.FC<TransactionsViewProps> = ({ transactions, onOpenSandbox }) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('ALL');

  const filtered = transactions.filter(t => {
    const matchesSearch = t.reference.toLowerCase().includes(searchTerm.toLowerCase()) ||
                          (t.idempotency_key && t.idempotency_key.toLowerCase().includes(searchTerm.toLowerCase()));
    const matchesStatus = statusFilter === 'ALL' || t.status === statusFilter;
    return matchesSearch && matchesStatus;
  });

  return (
    <div className="animate-fade-in" style={{ padding: '32px', display: 'flex', flexDirection: 'column', gap: '24px' }}>
      {/* Controls Bar */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '16px' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flex: 1, maxWidth: '480px' }}>
          <div style={{
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            background: 'var(--bg-card)',
            border: '1px solid var(--border-subtle)',
            borderRadius: 'var(--radius-md)',
            padding: '8px 14px',
            width: '100%',
          }}>
            <Search size={16} color="var(--text-muted)" />
            <input
              type="text"
              placeholder="Search by reference (e.g. TXN-...) or idempotency key..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              style={{
                background: 'transparent',
                border: 'none',
                outline: 'none',
                color: '#fff',
                fontSize: '13px',
                width: '100%',
                fontFamily: 'var(--font-body)',
              }}
            />
          </div>
        </div>

        {/* Filter Pills & Actions */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          {(['ALL', 'SUCCESS', 'PROCESSING', 'FAILED', 'PENDING'] as const).map(status => (
            <button
              key={status}
              onClick={() => setStatusFilter(status)}
              style={{
                padding: '6px 14px',
                borderRadius: 'var(--radius-md)',
                fontSize: '12px',
                fontWeight: 600,
                cursor: 'pointer',
                border: '1px solid',
                borderColor: statusFilter === status ? 'var(--border-accent)' : 'var(--border-subtle)',
                background: statusFilter === status ? 'rgba(99, 102, 241, 0.2)' : 'var(--bg-card)',
                color: statusFilter === status ? '#fff' : 'var(--text-secondary)',
                transition: 'all 0.15s ease',
              }}
            >
              {status}
            </button>
          ))}

          <button className="btn btn-primary" onClick={onOpenSandbox} style={{ marginLeft: '12px' }}>
            <ArrowLeftRight size={14} />
            <span>New Transaction</span>
          </button>
        </div>
      </div>

      {/* Transactions Table Card */}
      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <table className="data-table">
          <thead>
            <tr>
              <th>Transaction Reference</th>
              <th>Customer</th>
              <th>Gross Amount</th>
              <th>Platform Fee</th>
              <th>Net Payout</th>
              <th>Payment Method</th>
              <th>Idempotency Key</th>
              <th>Status</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((t) => (
              <tr key={t.id}>
                <td className="mono" style={{ fontWeight: 600, color: '#fff' }}>
                  {t.reference}
                </td>
                <td>{t.customer_email ?? 'customer@example.com'}</td>
                <td className="mono" style={{ fontWeight: 700, color: '#fff' }}>
                  {apiService.formatMoney(t.amount, t.currency)}
                </td>
                <td className="mono" style={{ color: 'var(--text-secondary)', fontSize: '12px' }}>
                  {apiService.formatMoney(t.fee_amount, t.currency)}
                </td>
                <td className="mono" style={{ fontWeight: 600, color: 'var(--success)' }}>
                  {apiService.formatMoney(t.net_amount, t.currency)}
                </td>
                <td>
                  <span className="badge badge-neutral">{t.payment_method}</span>
                </td>
                <td className="mono" style={{ fontSize: '11px', color: 'var(--text-muted)' }}>
                  {t.idempotency_key ? t.idempotency_key : '—'}
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
                  {new Date(t.created_at).toLocaleString()}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};
