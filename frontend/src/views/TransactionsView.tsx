import React, { useState, useEffect, useCallback } from 'react';
import { 
  Search, 
  ArrowLeftRight, 
  RotateCw, 
  ChevronLeft, 
  ChevronRight, 
  X, 
  Clock, 
  Server, 
  AlertCircle,
  RotateCcw
} from 'lucide-react';
import { apiService } from '../services/api';
import type { Transaction, PaginationMeta } from '../types';
import { RefundModal } from '../components/RefundModal';

interface TransactionsViewProps {
  apiKey?: string;
  onOpenSandbox: () => void;
}

export const TransactionsView: React.FC<TransactionsViewProps> = ({ 
  apiKey = 'tiq_live_swiftpay_test_key_001', 
  onOpenSandbox 
}) => {
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [pagination, setPagination] = useState<PaginationMeta>({
    current_page: 1,
    per_page: 15,
    total: 0,
    last_page: 1,
  });
  const [currentPage, setCurrentPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState<string>('ALL');
  const [searchTerm, setSearchTerm] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Selected transaction for detailed audit drawer
  const [selectedTxnReference, setSelectedTxnReference] = useState<string | null>(null);
  const [selectedTxnDetails, setSelectedTxnDetails] = useState<any | null>(null);
  const [isLoadingDetails, setIsLoadingDetails] = useState(false);

  // Refund modal state
  const [refundModalTxn, setRefundModalTxn] = useState<Transaction | null>(null);

  // Fetch transactions from backend
  const fetchTransactions = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await apiService.getTransactions(apiKey, currentPage, 15, statusFilter);
      setTransactions(res.data);
      setPagination(res.pagination);
    } catch (err: any) {
      console.error('Failed to load transactions:', err);
      setError(err.message || 'Unable to connect to transaction service.');
    } finally {
      setIsLoading(false);
    }
  }, [apiKey, currentPage, statusFilter]);

  useEffect(() => {
    fetchTransactions();
  }, [fetchTransactions]);

  // Load detailed audit timeline when a row is clicked
  const handleSelectTransaction = async (reference: string) => {
    setSelectedTxnReference(reference);
    setIsLoadingDetails(true);
    try {
      const details = await apiService.getTransactionDetails(apiKey, reference);
      setSelectedTxnDetails(details);
    } catch (err) {
      console.error('Failed to load transaction details:', err);
    } finally {
      setIsLoadingDetails(false);
    }
  };

  // Client-side quick search filter on current page
  const filtered = transactions.filter(t => {
    if (!searchTerm.trim()) return true;
    const term = searchTerm.toLowerCase();
    return t.reference.toLowerCase().includes(term) ||
      (t.idempotency_key && t.idempotency_key.toLowerCase().includes(term)) ||
      (t.customer_email && t.customer_email.toLowerCase().includes(term));
  });

  return (
    <div className="animate-fade-in" style={{ padding: '32px', display: 'flex', flexDirection: 'column', gap: '24px' }}>
      
      {/* Controls Bar */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '16px', flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flex: 1, minWidth: '320px', maxWidth: '480px' }}>
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
              placeholder="Search by reference (e.g. TXN-...) or customer email..."
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
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
          {(['ALL', 'SUCCESS', 'PROCESSING', 'FAILED', 'PENDING'] as const).map(status => (
            <button
              key={status}
              onClick={() => {
                setStatusFilter(status);
                setCurrentPage(1);
              }}
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

          <button 
            className="btn btn-secondary" 
            onClick={fetchTransactions} 
            disabled={isLoading}
            title="Refresh transactions"
            style={{ padding: '8px 12px' }}
          >
            <RotateCw size={14} className={isLoading ? 'animate-spin' : ''} />
            <span>Refresh</span>
          </button>

          <button className="btn btn-primary" onClick={onOpenSandbox} style={{ marginLeft: '4px' }}>
            <ArrowLeftRight size={14} />
            <span>New Transaction</span>
          </button>
        </div>
      </div>

      {error && (
        <div style={{
          padding: '12px 16px',
          background: 'rgba(239, 68, 68, 0.1)',
          border: '1px solid rgba(239, 68, 68, 0.3)',
          borderRadius: 'var(--radius-md)',
          color: '#f87171',
          fontSize: '13px',
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
        }}>
          <AlertCircle size={16} />
          <span>{error}</span>
        </div>
      )}

      {/* Transactions Table Card */}
      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <table className="data-table">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Customer</th>
              <th>Gross Amount</th>
              <th>Platform Fee</th>
              <th>Net Payout</th>
              <th>Method</th>
              <th>Idempotency Key</th>
              <th>Status</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && transactions.length === 0 ? (
              <tr>
                <td colSpan={9} style={{ textAlign: 'center', padding: '36px', color: 'var(--text-secondary)' }}>
                  <RotateCw size={20} className="animate-spin" style={{ display: 'inline-block', marginBottom: '8px' }} />
                  <div>Loading live transactions from PostgreSQL...</div>
                </td>
              </tr>
            ) : filtered.length === 0 ? (
              <tr>
                <td colSpan={9} style={{ textAlign: 'center', padding: '36px', color: 'var(--text-secondary)' }}>
                  No transactions found matching the selected criteria.
                </td>
              </tr>
            ) : (
              filtered.map((t) => (
                <tr 
                  key={t.id} 
                  onClick={() => handleSelectTransaction(t.reference)}
                  style={{ cursor: 'pointer' }}
                  className="interactive-row"
                >
                  <td className="mono" style={{ fontWeight: 600, color: 'var(--accent-primary)' }}>
                    {t.reference}
                  </td>
                  <td>{t.customer_email ?? 'N/A'}</td>
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
                    {t.idempotency_key ? `${t.idempotency_key.slice(0, 14)}...` : '—'}
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
              ))
            )}
          </tbody>
        </table>

        {/* Server Pagination Footer */}
        <div style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          padding: '16px 24px',
          borderTop: '1px solid var(--border-subtle)',
          fontSize: '13px',
          color: 'var(--text-secondary)',
        }}>
          <div>
            Showing <strong>{transactions.length > 0 ? (pagination.current_page - 1) * pagination.per_page + 1 : 0}</strong> to{' '}
            <strong>{Math.min(pagination.current_page * pagination.per_page, pagination.total)}</strong> of{' '}
            <strong>{pagination.total}</strong> transactions
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <button
              className="btn btn-secondary"
              style={{ padding: '6px 12px', fontSize: '12px' }}
              disabled={currentPage <= 1 || isLoading}
              onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
            >
              <ChevronLeft size={14} />
              <span>Previous</span>
            </button>

            <span style={{ padding: '0 8px', fontWeight: 600, color: '#fff' }}>
              Page {pagination.current_page} of {Math.max(1, pagination.last_page)}
            </span>

            <button
              className="btn btn-secondary"
              style={{ padding: '6px 12px', fontSize: '12px' }}
              disabled={currentPage >= pagination.last_page || isLoading}
              onClick={() => setCurrentPage(p => p + 1)}
            >
              <span>Next</span>
              <ChevronRight size={14} />
            </button>
          </div>
        </div>
      </div>

      {/* Transaction Details Slide-Over Drawer */}
      {selectedTxnReference && (
        <div style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          background: 'rgba(0,0,0,0.6)',
          backdropFilter: 'blur(4px)',
          zIndex: 999,
          display: 'flex',
          justifyContent: 'flex-end',
        }} onClick={() => setSelectedTxnReference(null)}>
          <div 
            style={{
              width: '100%',
              maxWidth: '560px',
              height: '100%',
              background: 'var(--bg-card)',
              borderLeft: '1px solid var(--border-subtle)',
              padding: '32px',
              overflowY: 'auto',
              display: 'flex',
              flexDirection: 'column',
              gap: '24px',
            }}
            onClick={e => e.stopPropagation()}
          >
            {/* Header */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <span className="badge badge-info" style={{ marginBottom: '8px' }}>Transaction Audit Inspection</span>
                <h3 style={{ fontSize: '20px', fontWeight: 800, color: '#fff' }}>{selectedTxnReference}</h3>
              </div>
              <button 
                onClick={() => setSelectedTxnReference(null)}
                style={{ background: 'transparent', border: 'none', color: 'var(--text-muted)', cursor: 'pointer' }}
              >
                <X size={20} />
              </button>
            </div>

            {isLoadingDetails || !selectedTxnDetails ? (
              <div style={{ textAlign: 'center', padding: '48px', color: 'var(--text-secondary)' }}>
                <RotateCw size={24} className="animate-spin" style={{ display: 'inline-block', marginBottom: '8px' }} />
                <div>Fetching state machine timeline and attempts...</div>
              </div>
            ) : (
              <>
                {/* Financial Summary */}
                <div style={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(3, 1fr)',
                  gap: '12px',
                  background: 'rgba(255,255,255,0.02)',
                  border: '1px solid var(--border-subtle)',
                  borderRadius: 'var(--radius-md)',
                  padding: '16px',
                }}>
                  <div>
                    <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Gross Amount</div>
                    <div style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
                      {apiService.formatMoney(selectedTxnDetails.amount, selectedTxnDetails.currency)}
                    </div>
                  </div>
                  <div>
                    <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Platform Fee (1.5%)</div>
                    <div style={{ fontSize: '16px', fontWeight: 700, color: 'var(--text-secondary)' }}>
                      {apiService.formatMoney(selectedTxnDetails.fee_amount, selectedTxnDetails.currency)}
                    </div>
                  </div>
                  <div>
                    <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Net Settlement</div>
                    <div style={{ fontSize: '16px', fontWeight: 700, color: 'var(--success)' }}>
                      {apiService.formatMoney(selectedTxnDetails.net_amount, selectedTxnDetails.currency)}
                    </div>
                  </div>
                </div>

                {/* Refund Action Button */}
                {(selectedTxnDetails.status === 'SUCCESS' || selectedTxnDetails.status === 'PARTIALLY_REFUNDED') && (
                  <button
                    id="trigger-refund-btn"
                    onClick={() => {
                      const txnObj: Transaction = {
                        id: selectedTxnDetails.id,
                        reference: selectedTxnDetails.reference,
                        merchant_id: selectedTxnDetails.merchant_id || '',
                        customer_email: selectedTxnDetails.customer?.email || 'N/A',
                        amount: selectedTxnDetails.refundable_amount ?? selectedTxnDetails.amount,
                        fee_amount: selectedTxnDetails.fee_amount,
                        net_amount: selectedTxnDetails.net_amount,
                        currency: selectedTxnDetails.currency,
                        status: selectedTxnDetails.status,
                        payment_method: selectedTxnDetails.payment_method,
                        idempotency_key: selectedTxnDetails.idempotency_key,
                        provider: selectedTxnDetails.provider,
                        created_at: selectedTxnDetails.created_at,
                      };
                      setRefundModalTxn(txnObj);
                    }}
                    className="btn btn-secondary"
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      gap: '8px',
                      padding: '10px 16px',
                      fontSize: '13px',
                      fontWeight: 600,
                      color: '#f59e0b',
                      borderColor: 'rgba(245, 158, 11, 0.4)',
                      background: 'rgba(245, 158, 11, 0.08)',
                      width: '100%',
                      cursor: 'pointer',
                      borderRadius: 'var(--radius-md)',
                      transition: 'all 0.2s ease',
                    }}
                  >
                    <RotateCcw size={15} />
                    <span>Issue Refund {selectedTxnDetails.refundable_amount ? `(Available: ${apiService.formatMoney(selectedTxnDetails.refundable_amount, selectedTxnDetails.currency)})` : ''}</span>
                  </button>
                )}

                {/* Technical Metadata */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', fontSize: '12px' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                    <span style={{ color: 'var(--text-muted)' }}>Status</span>
                    <span style={{ fontWeight: 600, color: selectedTxnDetails.status === 'SUCCESS' ? 'var(--success)' : '#f87171' }}>
                      {selectedTxnDetails.status}
                    </span>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                    <span style={{ color: 'var(--text-muted)' }}>Customer</span>
                    <span style={{ color: '#fff' }}>{selectedTxnDetails.customer?.email || 'N/A'}</span>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                    <span style={{ color: 'var(--text-muted)' }}>Payment Method</span>
                    <span style={{ color: '#fff' }}>{selectedTxnDetails.payment_method}</span>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                    <span style={{ color: 'var(--text-muted)' }}>Idempotency Key</span>
                    <span className="mono" style={{ color: 'var(--accent-primary)' }}>{selectedTxnDetails.idempotency_key}</span>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                    <span style={{ color: 'var(--text-muted)' }}>Gateway Provider</span>
                    <span style={{ color: '#fff' }}>{selectedTxnDetails.provider || 'SIMULATED_GATEWAY'}</span>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                    <span style={{ color: 'var(--text-muted)' }}>Provider Reference</span>
                    <span className="mono" style={{ color: 'var(--text-secondary)' }}>{selectedTxnDetails.provider_reference || 'N/A'}</span>
                  </div>
                </div>

                {/* State Machine Event Timeline */}
                <div>
                  <h4 style={{ fontSize: '14px', fontWeight: 700, color: '#fff', marginBottom: '12px', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <Clock size={16} color="var(--accent-primary)" />
                    <span>State Machine Transition Timeline</span>
                  </h4>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                    {(selectedTxnDetails.events || []).map((ev: any, idx: number) => (
                      <div key={idx} style={{
                        padding: '10px 14px',
                        background: 'rgba(255,255,255,0.02)',
                        borderLeft: '3px solid var(--accent-primary)',
                        borderRadius: '0 6px 6px 0',
                        fontSize: '12px',
                      }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '4px' }}>
                          <span style={{ fontWeight: 600, color: '#fff' }}>
                            {ev.from_status ? `${ev.from_status} → ${ev.to_status}` : ev.to_status}
                          </span>
                          <span style={{ color: 'var(--text-muted)', fontSize: '11px' }}>
                            {new Date(ev.created_at).toLocaleTimeString()}
                          </span>
                        </div>
                        <div style={{ color: 'var(--text-secondary)', fontSize: '11px' }}>
                          Event: {ev.event_type} • Trigger: {ev.triggered_by}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Gateway Payment Attempts */}
                <div>
                  <h4 style={{ fontSize: '14px', fontWeight: 700, color: '#fff', marginBottom: '12px', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <Server size={16} color="var(--accent-primary)" />
                    <span>Gateway Routing & Provider Attempts</span>
                  </h4>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                    {(selectedTxnDetails.payment_attempts || []).map((att: any, idx: number) => (
                      <div key={idx} style={{
                        padding: '10px 14px',
                        background: 'rgba(255,255,255,0.02)',
                        border: '1px solid var(--border-subtle)',
                        borderRadius: '6px',
                        fontSize: '12px',
                      }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '4px' }}>
                          <span style={{ fontWeight: 600, color: '#fff' }}>
                            Attempt #{att.attempt_number}: {att.provider}
                          </span>
                          <span className={`badge ${att.status === 'SUCCESS' ? 'badge-success' : 'badge-danger'}`} style={{ fontSize: '10px' }}>
                            {att.status}
                          </span>
                        </div>
                        <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--text-muted)', fontSize: '11px' }}>
                          <span>Latency: {att.latency_ms}ms</span>
                          <span>Ref: {att.provider_reference || 'N/A'}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Processed Refunds Section */}
                {selectedTxnDetails.refunds && selectedTxnDetails.refunds.length > 0 && (
                  <div>
                    <h4 style={{ fontSize: '14px', fontWeight: 700, color: '#fff', marginBottom: '12px', display: 'flex', alignItems: 'center', gap: '8px' }}>
                      <RotateCcw size={16} color="#f59e0b" />
                      <span>Processed Refunds ({selectedTxnDetails.refunds.length})</span>
                    </h4>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                      {selectedTxnDetails.refunds.map((rf: any, idx: number) => (
                        <div key={idx} style={{
                          padding: '10px 14px',
                          background: 'rgba(245, 158, 11, 0.05)',
                          border: '1px solid rgba(245, 158, 11, 0.2)',
                          borderRadius: '6px',
                          fontSize: '12px',
                        }}>
                          <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '4px' }}>
                            <span style={{ fontWeight: 600, color: '#f59e0b' }}>
                              {apiService.formatMoney(rf.amount, rf.currency)}
                            </span>
                            <span className="badge badge-warning" style={{ fontSize: '10px' }}>
                              {rf.status}
                            </span>
                          </div>
                          <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--text-muted)', fontSize: '11px' }}>
                            <span>Ref: {rf.reference}</span>
                            <span>{new Date(rf.created_at).toLocaleTimeString()}</span>
                          </div>
                          {rf.reason && (
                            <div style={{ color: 'var(--text-secondary)', fontSize: '11px', marginTop: '4px' }}>
                              Reason: {rf.reason}
                            </div>
                          )}
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      )}

      {/* Full or Partial Refund Modal */}
      {refundModalTxn && (
        <RefundModal
          transaction={refundModalTxn}
          apiKey={apiKey}
          onClose={() => setRefundModalTxn(null)}
          onSuccess={() => {
            fetchTransactions();
            if (selectedTxnReference) {
              handleSelectTransaction(selectedTxnReference);
            }
          }}
        />
      )}
    </div>
  );
};
