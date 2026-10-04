import React, { useState, useEffect, useMemo } from 'react';
import { Scale, ArrowUpRight, ArrowDownRight, ShieldCheck, RefreshCw, AlertCircle } from 'lucide-react';
import { apiService } from '../services/api';
import type { LedgerAccount, LedgerEntry } from '../types';

interface LedgerViewProps {
  accounts?: LedgerAccount[];
  entries?: LedgerEntry[];
  apiKey?: string;
}

export const LedgerView: React.FC<LedgerViewProps> = ({
  accounts: initialAccounts = [],
  entries: initialEntries = [],
  apiKey = 'tiq_live_pub_Fp6Wc50bYe6sFAt081ewvb2z',
}) => {
  const [accounts, setAccounts] = useState<LedgerAccount[]>(initialAccounts);
  const [entries, setEntries] = useState<LedgerEntry[]>(initialEntries);
  const [integrity, setIntegrity] = useState<{
    balanced: boolean;
    total_debit: number;
    total_credit: number;
    net_variance: number;
    total_entries: number;
  } | null>(null);

  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [selectedType, setSelectedType] = useState<string>('ALL');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [fetchError, setFetchError] = useState<string | null>(null);

  const loadLedgerData = async () => {
    setIsLoading(true);
    setFetchError(null);
    try {
      const [accs, ents, integ] = await Promise.all([
        apiService.getLedgerAccounts(apiKey),
        apiService.getLedgerEntries(apiKey, 100),
        apiService.getLedgerIntegrity(apiKey),
      ]);

      if (accs && accs.length > 0) {
        setAccounts(accs);
      }
      if (ents) {
        setEntries(ents);
      }
      if (integ) {
        setIntegrity(integ);
      }
    } catch (err: any) {
      console.warn('Could not fetch live ledger from backend, retaining current state:', err);
      setFetchError(err.message || 'Failed to sync with live backend ledger');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadLedgerData();
  }, [apiKey]);

  const filteredEntries = useMemo(() => {
    return entries.filter((e) => {
      const matchesType = selectedType === 'ALL' || e.entry_type === selectedType;
      const matchesQuery =
        !searchQuery ||
        e.reference?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        e.debit_account?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        e.credit_account?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        e.description?.toLowerCase().includes(searchQuery.toLowerCase());
      return matchesType && matchesQuery;
    });
  }, [entries, selectedType, searchQuery]);

  const entryTypes = [
    'ALL',
    'PAYMENT_CAPTURED',
    'PLATFORM_FEE',
    'SETTLEMENT_INITIATED',
    'SETTLEMENT_PAYOUT',
  ];

  return (
    <div className="animate-fade-in" style={{ padding: '32px', display: 'flex', flexDirection: 'column', gap: '28px' }}>
      {/* Overview Banner */}
      <div style={{
        background: 'linear-gradient(135deg, rgba(6, 182, 212, 0.08) 0%, rgba(99, 102, 241, 0.06) 100%)',
        border: '1px solid var(--info-border)',
        borderRadius: 'var(--radius-lg)',
        padding: '24px 28px',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        flexWrap: 'wrap',
        gap: '20px',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '16px' }}>
          <div style={{
            width: '48px',
            height: '48px',
            borderRadius: '14px',
            background: 'rgba(6, 182, 212, 0.15)',
            color: 'var(--info)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}>
            <Scale size={24} />
          </div>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <h3 style={{ fontSize: '18px', fontWeight: 700, color: '#fff' }}>
                Double-Entry General Ledger System
              </h3>
              <span className="badge badge-info" style={{ fontSize: '10px' }}>
                Real-Time Audited
              </span>
            </div>
            <p style={{ fontSize: '13px', color: 'var(--text-secondary)', marginTop: '3px' }}>
              Strict financial accounting: Every mutation produces balanced debit and credit entries with mathematical invariance.
            </p>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '14px', flexWrap: 'wrap' }}>
          {integrity && (
            <div style={{
              display: 'flex',
              gap: '16px',
              padding: '8px 16px',
              background: 'rgba(0, 0, 0, 0.3)',
              borderRadius: 'var(--radius-md)',
              border: '1px solid var(--border-subtle)',
              fontSize: '12px',
            }}>
              <div>
                <span style={{ color: 'var(--text-muted)' }}>Debits: </span>
                <span className="mono" style={{ color: 'var(--danger)', fontWeight: 600 }}>
                  {apiService.formatMoney(integrity.total_debit)}
                </span>
              </div>
              <div style={{ borderLeft: '1px solid var(--border-subtle)', paddingLeft: '16px' }}>
                <span style={{ color: 'var(--text-muted)' }}>Credits: </span>
                <span className="mono" style={{ color: 'var(--success)', fontWeight: 600 }}>
                  {apiService.formatMoney(integrity.total_credit)}
                </span>
              </div>
              <div style={{ borderLeft: '1px solid var(--border-subtle)', paddingLeft: '16px' }}>
                <span style={{ color: 'var(--text-muted)' }}>Variance: </span>
                <span className="mono" style={{ color: integrity.balanced ? 'var(--success)' : 'var(--danger)', fontWeight: 700 }}>
                  {apiService.formatMoney(integrity.net_variance)}
                </span>
              </div>
            </div>
          )}

          <div className={integrity?.balanced !== false ? 'badge badge-success' : 'badge badge-danger'}>
            <ShieldCheck size={14} />
            <span>{integrity?.balanced !== false ? 'Invariant: Balanced' : 'Invariant: Violated'}</span>
          </div>

          <button
            onClick={loadLedgerData}
            disabled={isLoading}
            className="btn btn-secondary"
            style={{ padding: '8px 14px', fontSize: '12px', display: 'flex', alignItems: 'center', gap: '6px' }}
          >
            <RefreshCw size={13} className={isLoading ? 'animate-spin' : ''} />
            <span>{isLoading ? 'Syncing...' : 'Refresh'}</span>
          </button>
        </div>
      </div>

      {fetchError && (
        <div style={{
          padding: '12px 16px',
          background: 'var(--warning-bg)',
          border: '1px solid var(--warning-border)',
          borderRadius: 'var(--radius-md)',
          color: 'var(--warning)',
          fontSize: '13px',
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
        }}>
          <AlertCircle size={15} />
          <span>Notice: {fetchError}. Showing cached data.</span>
        </div>
      )}

      {/* Chart of Accounts Grid */}
      <div>
        <div style={{ fontSize: '13px', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-secondary)', marginBottom: '14px' }}>
          Chart of Accounts ({accounts.length})
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '16px' }}>
          {accounts.map((acc) => {
            const isAsset = acc.type === 'ASSET';
            const isRevenue = acc.type === 'REVENUE';

            return (
              <div key={acc.account_number} className="card card-interactive" style={{ padding: '20px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                  <span className="mono" style={{ fontSize: '11px', color: 'var(--text-muted)' }}>
                    {acc.account_number}
                  </span>
                  <span
                    className={`badge ${isRevenue ? 'badge-info' : isAsset ? 'badge-success' : 'badge-neutral'}`}
                    style={{ fontSize: '10px' }}
                  >
                    {acc.type}
                  </span>
                </div>
                <div style={{ fontSize: '14px', fontWeight: 700, color: '#fff', marginTop: '12px' }}>
                  {acc.name}
                </div>
                <div style={{
                  fontSize: '22px',
                  fontWeight: 800,
                  color: isRevenue ? 'var(--info)' : isAsset ? 'var(--success)' : '#e2e8f0',
                  marginTop: '8px',
                  fontFamily: 'var(--font-mono)',
                }}>
                  {apiService.formatMoney(acc.balance, acc.currency)}
                </div>
                <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '8px', display: 'flex', justifyContent: 'space-between' }}>
                  <span>{acc.classification}</span>
                  <span style={{ color: 'var(--text-dim)' }}>{acc.currency}</span>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Journal Entries Log */}
      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <div style={{
          padding: '20px 24px',
          borderBottom: '1px solid var(--border-subtle)',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          flexWrap: 'wrap',
          gap: '16px',
        }}>
          <div>
            <h4 style={{ fontSize: '15px', fontWeight: 700, color: '#fff' }}>
              Balanced Journal Entries ({filteredEntries.length})
            </h4>
            <p style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
              Immutable chronological audit of all ledger debits and credits
            </p>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }}>
            {/* Search Input */}
            <input
              type="text"
              placeholder="Search reference, account, or note..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              style={{
                background: 'rgba(0, 0, 0, 0.25)',
                border: '1px solid var(--border-subtle)',
                borderRadius: 'var(--radius-sm)',
                padding: '6px 12px',
                color: '#fff',
                fontSize: '12px',
                width: '220px',
              }}
            />

            {/* Filter Buttons */}
            <div style={{ display: 'flex', gap: '6px' }}>
              {entryTypes.map((type) => (
                <button
                  key={type}
                  onClick={() => setSelectedType(type)}
                  style={{
                    padding: '4px 10px',
                    borderRadius: 'var(--radius-sm)',
                    fontSize: '11px',
                    fontWeight: 600,
                    cursor: 'pointer',
                    background: selectedType === type ? 'var(--accent-primary)' : 'rgba(255, 255, 255, 0.05)',
                    color: selectedType === type ? '#fff' : 'var(--text-secondary)',
                    border: '1px solid',
                    borderColor: selectedType === type ? 'var(--accent-primary)' : 'var(--border-subtle)',
                    transition: 'all 0.15s ease',
                  }}
                >
                  {type === 'ALL' ? 'All Types' : type.replace(/_/g, ' ')}
                </button>
              ))}
            </div>
          </div>
        </div>

        <table className="data-table">
          <thead>
            <tr>
              <th>Journal Reference</th>
              <th>Entry Type</th>
              <th>Debit Account</th>
              <th>Credit Account</th>
              <th>Amount</th>
              <th>Description</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            {filteredEntries.length === 0 ? (
              <tr>
                <td colSpan={7} style={{ textAlign: 'center', padding: '36px', color: 'var(--text-muted)' }}>
                  No journal entries matched your search. Trigger a payment in the Sandbox to generate live entries!
                </td>
              </tr>
            ) : (
              filteredEntries.map((e) => (
                <tr key={e.id || e.reference}>
                  <td className="mono" style={{ fontWeight: 600, color: '#fff' }}>
                    {e.reference}
                  </td>
                  <td>
                    <span className={`badge ${
                      e.entry_type === 'PAYMENT_CAPTURED'
                        ? 'badge-success'
                        : e.entry_type === 'PLATFORM_FEE'
                        ? 'badge-info'
                        : 'badge-neutral'
                    }`} style={{ fontSize: '10px' }}>
                      {e.entry_type}
                    </span>
                  </td>
                  <td className="mono" style={{ color: 'var(--danger)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
                      <ArrowDownRight size={13} />
                      <span>{e.debit_account}</span>
                    </div>
                  </td>
                  <td className="mono" style={{ color: 'var(--success)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
                      <ArrowUpRight size={13} />
                      <span>{e.credit_account}</span>
                    </div>
                  </td>
                  <td className="mono" style={{ fontWeight: 700, color: '#fff' }}>
                    {apiService.formatMoney(e.amount, e.currency || 'NGN')}
                  </td>
                  <td style={{ fontSize: '12px', maxWidth: '240px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                    {e.description}
                  </td>
                  <td style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
                    {e.created_at ? new Date(e.created_at).toLocaleString() : 'N/A'}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};
