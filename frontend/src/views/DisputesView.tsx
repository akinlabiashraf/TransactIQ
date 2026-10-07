import React, { useState, useEffect, useCallback } from 'react';
import { 
  AlertOctagon, 
  Search, 
  RotateCw, 
  ChevronLeft, 
  ChevronRight, 
  X, 
  ShieldAlert, 
  FileText, 
  CheckCircle2, 
  XCircle,
  Plus
} from 'lucide-react';
import { apiService } from '../services/api';
import type { DisputeItem, PaginationMeta } from '../types';

interface DisputesViewProps {
  apiKey?: string;
}

export const DisputesView: React.FC<DisputesViewProps> = ({
  apiKey = 'tiq_live_swiftpay_test_key_001',
}) => {
  const [disputes, setDisputes] = useState<DisputeItem[]>([]);
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

  // Selected Dispute for Slide-Over Drawer
  const [selectedDispute, setSelectedDispute] = useState<DisputeItem | null>(null);

  // Form states for Evidence & Resolution
  const [evidenceUrl, setEvidenceUrl] = useState('');
  const [evidenceNote, setEvidenceNote] = useState('');
  const [isSubmittingEvidence, setIsSubmittingEvidence] = useState(false);
  const [isResolving, setIsResolving] = useState(false);

  // Open Dispute Modal State
  const [isOpenModalActive, setIsOpenModalActive] = useState(false);
  const [newTxnRef, setNewTxnRef] = useState('');
  const [newReason, setNewReason] = useState('CHARGEBACK_FRAUD');
  const [isCreatingDispute, setIsCreatingDispute] = useState(false);

  const fetchDisputes = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await apiService.getDisputes(apiKey, currentPage, 15, statusFilter);
      setDisputes(res.data);
      setPagination(res.pagination);
    } catch (err: any) {
      console.error('Failed to load disputes:', err);
      setError(err.message || 'Unable to connect to dispute service.');
    } finally {
      setIsLoading(false);
    }
  }, [apiKey, currentPage, statusFilter]);

  useEffect(() => {
    fetchDisputes();
  }, [fetchDisputes]);

  const handleSelectDispute = (dispute: DisputeItem) => {
    setSelectedDispute(dispute);
    setEvidenceUrl('');
    setEvidenceNote('');
  };

  const handleSubmitEvidence = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedDispute) return;
    setIsSubmittingEvidence(true);
    try {
      const evidenceObj: Record<string, any> = {};
      if (evidenceUrl) evidenceObj['proof_url'] = evidenceUrl;
      if (evidenceNote) evidenceObj['merchant_explanation'] = evidenceNote;
      evidenceObj['submitted_at'] = new Date().toISOString();

      await apiService.submitDisputeEvidence(apiKey, selectedDispute.reference, evidenceObj);
      fetchDisputes();
      setSelectedDispute(prev => prev ? { ...prev, status: 'UNDER_REVIEW', evidence: evidenceObj } : null);
      setEvidenceUrl('');
      setEvidenceNote('');
    } catch (err: any) {
      alert(err.message || 'Failed to submit evidence');
    } finally {
      setIsSubmittingEvidence(false);
    }
  };

  const handleResolve = async (outcome: 'WON' | 'LOST') => {
    if (!selectedDispute) return;
    if (!confirm(`Are you sure you want to adjudicate this dispute as ${outcome}?`)) return;

    setIsResolving(true);
    try {
      await apiService.resolveDispute(
        apiKey,
        selectedDispute.reference,
        outcome,
        `Adjudicated via Operations Console as ${outcome}`
      );
      fetchDisputes();
      setSelectedDispute(prev => prev ? { ...prev, status: outcome } : null);
    } catch (err: any) {
      alert(err.message || 'Failed to resolve dispute');
    } finally {
      setIsResolving(false);
    }
  };

  const handleCreateDispute = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTxnRef) return;
    setIsCreatingDispute(true);
    try {
      await apiService.createDispute(apiKey, newTxnRef.trim(), undefined, newReason);
      setIsOpenModalActive(false);
      setNewTxnRef('');
      fetchDisputes();
    } catch (err: any) {
      alert(err.message || 'Failed to open dispute');
    } finally {
      setIsCreatingDispute(false);
    }
  };

  const filtered = disputes.filter(d => {
    if (!searchTerm.trim()) return true;
    const term = searchTerm.toLowerCase();
    return (
      d.reference.toLowerCase().includes(term) ||
      (d.transaction?.reference || '').toLowerCase().includes(term)
    );
  });

  const openCount = disputes.filter(d => d.status === 'OPEN' || d.status === 'UNDER_REVIEW').length;
  const wonCount = disputes.filter(d => d.status === 'WON').length;
  const totalDecided = wonCount + disputes.filter(d => d.status === 'LOST').length;
  const winRate = totalDecided > 0 ? Math.round((wonCount / totalDecided) * 100) : 100;
  const totalHeldMinor = disputes.filter(d => d.status === 'OPEN' || d.status === 'UNDER_REVIEW').reduce((acc, d) => acc + d.amount, 0);

  return (
    <div className="view-container">
      {/* View Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '24px' }}>
        <div>
          <h1 style={{ fontSize: '26px', fontWeight: 700, margin: '0 0 6px 0', color: '#fff' }}>
            Chargebacks & Disputes Console
          </h1>
          <p style={{ margin: 0, color: 'var(--text-secondary, #94a3b8)', fontSize: '14px' }}>
            Manage payment disputes, escrow reserve liability holds, and counter-evidence workflows.
          </p>
        </div>

        <div style={{ display: 'flex', gap: '12px' }}>
          <button
            onClick={() => setIsOpenModalActive(true)}
            style={{
              padding: '10px 16px',
              borderRadius: '8px',
              background: 'linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)',
              border: 'none',
              color: '#fff',
              fontSize: '13px',
              fontWeight: 600,
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '6px',
              boxShadow: '0 4px 12px rgba(99, 102, 241, 0.3)',
            }}
          >
            <Plus size={16} />
            <span>Open Dispute</span>
          </button>

          <button
            onClick={() => fetchDisputes()}
            disabled={isLoading}
            style={{
              padding: '10px 14px',
              borderRadius: '8px',
              backgroundColor: 'rgba(255, 255, 255, 0.05)',
              border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
              color: '#fff',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '6px',
            }}
          >
            <RotateCw size={16} className={isLoading ? 'spin-icon' : ''} />
            <span>Refresh</span>
          </button>
        </div>
      </div>

      {error && (
        <div style={{
          backgroundColor: 'rgba(239, 68, 68, 0.12)',
          border: '1px solid rgba(239, 68, 68, 0.3)',
          color: '#f87171',
          padding: '12px 16px',
          borderRadius: '8px',
          marginBottom: '20px',
          fontSize: '13px'
        }}>
          {error}
        </div>
      )}

      {/* Metric Cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '16px', marginBottom: '24px' }}>
        <div className="metric-card">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontSize: '13px', color: 'var(--text-secondary, #94a3b8)' }}>Active Disputes</span>
            <AlertOctagon size={18} style={{ color: '#f59e0b' }} />
          </div>
          <div style={{ fontSize: '24px', fontWeight: 700, color: '#fff', marginTop: '10px' }}>
            {openCount}
          </div>
          <div style={{ fontSize: '12px', color: '#f59e0b', marginTop: '4px' }}>
            Requires Merchant Action
          </div>
        </div>

        <div className="metric-card">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontSize: '13px', color: 'var(--text-secondary, #94a3b8)' }}>Escrow Held in Reserve</span>
            <ShieldAlert size={18} style={{ color: '#ef4444' }} />
          </div>
          <div style={{ fontSize: '24px', fontWeight: 700, color: '#fff', marginTop: '10px' }}>
            {apiService.formatMoney(totalHeldMinor)}
          </div>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary, #94a3b8)', marginTop: '4px' }}>
            Held in Escrow Liability Account
          </div>
        </div>

        <div className="metric-card">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontSize: '13px', color: 'var(--text-secondary, #94a3b8)' }}>Dispute Win Rate</span>
            <CheckCircle2 size={18} style={{ color: '#10b981' }} />
          </div>
          <div style={{ fontSize: '24px', fontWeight: 700, color: '#fff', marginTop: '10px' }}>
            {winRate}%
          </div>
          <div style={{ fontSize: '12px', color: '#10b981', marginTop: '4px' }}>
            {wonCount} Won / {totalDecided} Total Decided
          </div>
        </div>

        <div className="metric-card">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontSize: '13px', color: 'var(--text-secondary, #94a3b8)' }}>Total Recorded</span>
            <FileText size={18} style={{ color: '#818cf8' }} />
          </div>
          <div style={{ fontSize: '24px', fontWeight: 700, color: '#fff', marginTop: '10px' }}>
            {pagination.total}
          </div>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary, #94a3b8)', marginTop: '4px' }}>
            Historical Disputes
          </div>
        </div>
      </div>

      {/* Filter and Search Bar */}
      <div style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: '16px',
        backgroundColor: 'var(--bg-card, #121622)',
        borderRadius: '12px',
        border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.08))',
        marginBottom: '20px',
      }}>
        {/* Status Filters */}
        <div style={{ display: 'flex', gap: '8px' }}>
          {['ALL', 'OPEN', 'UNDER_REVIEW', 'WON', 'LOST'].map((status) => (
            <button
              key={status}
              onClick={() => {
                setStatusFilter(status);
                setCurrentPage(1);
              }}
              style={{
                padding: '8px 14px',
                borderRadius: '8px',
                border: statusFilter === status ? '1px solid #6366f1' : '1px solid transparent',
                backgroundColor: statusFilter === status ? 'rgba(99, 102, 241, 0.15)' : 'transparent',
                color: statusFilter === status ? '#a5b4fc' : 'var(--text-secondary, #94a3b8)',
                cursor: 'pointer',
                fontSize: '13px',
                fontWeight: 600,
              }}
            >
              {status.replace('_', ' ')}
            </button>
          ))}
        </div>

        {/* Search */}
        <div style={{ position: 'relative', width: '280px' }}>
          <Search size={16} style={{ position: 'absolute', left: '12px', top: '10px', color: '#64748b' }} />
          <input
            type="text"
            placeholder="Search dispute reference..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            style={{
              width: '100%',
              padding: '8px 12px 8px 36px',
              backgroundColor: 'rgba(255, 255, 255, 0.04)',
              border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
              borderRadius: '8px',
              color: '#fff',
              fontSize: '13px',
              boxSizing: 'border-box',
            }}
          />
        </div>
      </div>

      {/* Table */}
      <div style={{
        backgroundColor: 'var(--bg-card, #121622)',
        borderRadius: '12px',
        border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.08))',
        overflow: 'hidden',
      }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '13px' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.08))', backgroundColor: 'rgba(255, 255, 255, 0.02)' }}>
              <th style={{ padding: '14px 18px', color: 'var(--text-secondary, #94a3b8)', fontWeight: 600 }}>Dispute Reference</th>
              <th style={{ padding: '14px 18px', color: 'var(--text-secondary, #94a3b8)', fontWeight: 600 }}>Transaction</th>
              <th style={{ padding: '14px 18px', color: 'var(--text-secondary, #94a3b8)', fontWeight: 600 }}>Disputed Amount</th>
              <th style={{ padding: '14px 18px', color: 'var(--text-secondary, #94a3b8)', fontWeight: 600 }}>Reason</th>
              <th style={{ padding: '14px 18px', color: 'var(--text-secondary, #94a3b8)', fontWeight: 600 }}>Status</th>
              <th style={{ padding: '14px 18px', color: 'var(--text-secondary, #94a3b8)', fontWeight: 600 }}>Evidence Deadline</th>
              <th style={{ padding: '14px 18px', color: 'var(--text-secondary, #94a3b8)', fontWeight: 600 }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr>
                <td colSpan={7} style={{ padding: '40px', textAlign: 'center', color: 'var(--text-secondary, #94a3b8)' }}>
                  Loading disputes...
                </td>
              </tr>
            ) : filtered.length === 0 ? (
              <tr>
                <td colSpan={7} style={{ padding: '40px', textAlign: 'center', color: 'var(--text-secondary, #94a3b8)' }}>
                  No disputes found matching criteria.
                </td>
              </tr>
            ) : (
              filtered.map((d) => (
                <tr
                  key={d.id}
                  onClick={() => handleSelectDispute(d)}
                  style={{
                    borderBottom: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.05))',
                    cursor: 'pointer',
                    transition: 'background-color 0.15s ease',
                  }}
                  onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.02)')}
                  onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = 'transparent')}
                >
                  <td style={{ padding: '14px 18px', fontWeight: 600, color: '#818cf8' }}>
                    {d.reference}
                  </td>
                  <td style={{ padding: '14px 18px', color: '#e2e8f0' }}>
                    {d.transaction?.reference || 'N/A'}
                  </td>
                  <td style={{ padding: '14px 18px', fontWeight: 600, color: '#ef4444' }}>
                    {apiService.formatMoney(d.amount, d.currency)}
                  </td>
                  <td style={{ padding: '14px 18px', color: 'var(--text-secondary, #94a3b8)' }}>
                    {d.reason.replace(/_/g, ' ')}
                  </td>
                  <td style={{ padding: '14px 18px' }}>
                    <span style={{
                      padding: '4px 10px',
                      borderRadius: '20px',
                      fontSize: '11px',
                      fontWeight: 700,
                      backgroundColor:
                        d.status === 'WON'
                          ? 'rgba(16, 185, 129, 0.15)'
                          : d.status === 'LOST'
                          ? 'rgba(239, 68, 68, 0.15)'
                          : d.status === 'UNDER_REVIEW'
                          ? 'rgba(59, 130, 246, 0.15)'
                          : 'rgba(245, 158, 11, 0.15)',
                      color:
                        d.status === 'WON'
                          ? '#34d399'
                          : d.status === 'LOST'
                          ? '#f87171'
                          : d.status === 'UNDER_REVIEW'
                          ? '#60a5fa'
                          : '#fbbf24',
                    }}>
                      {d.status}
                    </span>
                  </td>
                  <td style={{ padding: '14px 18px', color: 'var(--text-secondary, #94a3b8)', fontSize: '12px' }}>
                    {d.due_at ? new Date(d.due_at).toLocaleDateString() : 'N/A'}
                  </td>
                  <td style={{ padding: '14px 18px' }}>
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        handleSelectDispute(d);
                      }}
                      style={{
                        padding: '6px 12px',
                        borderRadius: '6px',
                        border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
                        backgroundColor: 'rgba(255, 255, 255, 0.04)',
                        color: '#a5b4fc',
                        fontSize: '12px',
                        cursor: 'pointer',
                        fontWeight: 500,
                      }}
                    >
                      View & Triage
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>

        {/* Pagination Footer */}
        <div style={{
          padding: '14px 18px',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          borderTop: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.08))',
          color: 'var(--text-secondary, #94a3b8)',
          fontSize: '13px',
        }}>
          <div>
            Showing {filtered.length} of {pagination.total} disputes
          </div>

          <div style={{ display: 'flex', gap: '8px' }}>
            <button
              onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
              disabled={currentPage <= 1}
              style={{
                padding: '6px 12px',
                borderRadius: '6px',
                border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
                backgroundColor: 'transparent',
                color: currentPage <= 1 ? '#475569' : '#fff',
                cursor: currentPage <= 1 ? 'not-allowed' : 'pointer',
                display: 'flex',
                alignItems: 'center',
              }}
            >
              <ChevronLeft size={16} />
            </button>
            <span style={{ display: 'flex', alignItems: 'center', padding: '0 8px' }}>
              Page {pagination.current_page} of {pagination.last_page}
            </span>
            <button
              onClick={() => setCurrentPage(p => Math.min(pagination.last_page, p + 1))}
              disabled={currentPage >= pagination.last_page}
              style={{
                padding: '6px 12px',
                borderRadius: '6px',
                border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
                backgroundColor: 'transparent',
                color: currentPage >= pagination.last_page ? '#475569' : '#fff',
                cursor: currentPage >= pagination.last_page ? 'not-allowed' : 'pointer',
                display: 'flex',
                alignItems: 'center',
              }}
            >
              <ChevronRight size={16} />
            </button>
          </div>
        </div>
      </div>

      {/* Slide-over Drawer for Selected Dispute */}
      {selectedDispute && (
        <div style={{
          position: 'fixed',
          top: 0,
          right: 0,
          bottom: 0,
          width: '520px',
          backgroundColor: 'var(--bg-card, #121622)',
          borderLeft: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.12))',
          boxShadow: '-10px 0 30px rgba(0, 0, 0, 0.5)',
          zIndex: 1000,
          padding: '24px',
          overflowY: 'auto',
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'space-between',
        }}>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '20px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <AlertOctagon size={22} style={{ color: '#f59e0b' }} />
                <h3 style={{ margin: 0, color: '#fff', fontSize: '18px' }}>Dispute Details</h3>
              </div>
              <button
                onClick={() => setSelectedDispute(null)}
                style={{
                  background: 'transparent',
                  border: 'none',
                  color: 'var(--text-secondary, #94a3b8)',
                  cursor: 'pointer',
                  padding: '6px',
                }}
              >
                <X size={20} />
              </button>
            </div>

            {/* Dispute Summary Card */}
            <div style={{
              padding: '16px',
              backgroundColor: 'rgba(255, 255, 255, 0.03)',
              borderRadius: '10px',
              border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.08))',
              marginBottom: '20px',
            }}>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', fontSize: '13px' }}>
                <div>
                  <span style={{ color: 'var(--text-secondary, #94a3b8)' }}>Reference:</span>
                  <div style={{ fontWeight: 600, color: '#818cf8', marginTop: '2px' }}>{selectedDispute.reference}</div>
                </div>
                <div>
                  <span style={{ color: 'var(--text-secondary, #94a3b8)' }}>Amount:</span>
                  <div style={{ fontWeight: 600, color: '#ef4444', marginTop: '2px' }}>
                    {apiService.formatMoney(selectedDispute.amount, selectedDispute.currency)}
                  </div>
                </div>
                <div>
                  <span style={{ color: 'var(--text-secondary, #94a3b8)' }}>Reason:</span>
                  <div style={{ color: '#fff', marginTop: '2px' }}>{selectedDispute.reason.replace(/_/g, ' ')}</div>
                </div>
                <div>
                  <span style={{ color: 'var(--text-secondary, #94a3b8)' }}>Status:</span>
                  <div style={{ marginTop: '2px' }}>
                    <span style={{
                      padding: '2px 8px',
                      borderRadius: '12px',
                      fontSize: '11px',
                      fontWeight: 700,
                      backgroundColor: selectedDispute.status === 'WON' ? '#065f46' : selectedDispute.status === 'LOST' ? '#7f1d1d' : '#78350f',
                      color: '#fff',
                    }}>
                      {selectedDispute.status}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            {/* Evidence History */}
            <div style={{ marginBottom: '24px' }}>
              <h4 style={{ margin: '0 0 10px 0', fontSize: '14px', color: '#fff' }}>Evidence Records</h4>
              {selectedDispute.evidence ? (
                <pre style={{
                  padding: '12px',
                  backgroundColor: 'rgba(0, 0, 0, 0.3)',
                  borderRadius: '8px',
                  color: '#94a3b8',
                  fontSize: '12px',
                  overflowX: 'auto',
                }}>
                  {JSON.stringify(selectedDispute.evidence, null, 2)}
                </pre>
              ) : (
                <p style={{ margin: 0, fontSize: '13px', color: 'var(--text-secondary, #94a3b8)' }}>
                  No counter-evidence submitted yet.
                </p>
              )}
            </div>

            {/* Evidence Submission Form (if OPEN / UNDER_REVIEW) */}
            {(selectedDispute.status === 'OPEN' || selectedDispute.status === 'UNDER_REVIEW') && (
              <form onSubmit={handleSubmitEvidence} style={{ marginBottom: '24px' }}>
                <h4 style={{ margin: '0 0 10px 0', fontSize: '14px', color: '#fff' }}>Submit Defense Evidence</h4>
                <div style={{ marginBottom: '12px' }}>
                  <label style={{ display: 'block', fontSize: '12px', color: 'var(--text-secondary, #94a3b8)', marginBottom: '4px' }}>
                    Proof Document URL
                  </label>
                  <input
                    type="url"
                    placeholder="https://cloud-storage.com/proof-delivery.pdf"
                    value={evidenceUrl}
                    onChange={(e) => setEvidenceUrl(e.target.value)}
                    style={{
                      width: '100%',
                      padding: '8px 12px',
                      backgroundColor: 'rgba(255, 255, 255, 0.04)',
                      border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
                      borderRadius: '6px',
                      color: '#fff',
                      fontSize: '13px',
                      boxSizing: 'border-box',
                    }}
                  />
                </div>

                <div style={{ marginBottom: '12px' }}>
                  <label style={{ display: 'block', fontSize: '12px', color: 'var(--text-secondary, #94a3b8)', marginBottom: '4px' }}>
                    Merchant Clarification Note
                  </label>
                  <textarea
                    rows={3}
                    placeholder="Provide details on buyer communication and signed delivery receipt..."
                    value={evidenceNote}
                    onChange={(e) => setEvidenceNote(e.target.value)}
                    style={{
                      width: '100%',
                      padding: '8px 12px',
                      backgroundColor: 'rgba(255, 255, 255, 0.04)',
                      border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
                      borderRadius: '6px',
                      color: '#fff',
                      fontSize: '13px',
                      boxSizing: 'border-box',
                    }}
                  />
                </div>

                <button
                  type="submit"
                  disabled={isSubmittingEvidence || (!evidenceUrl && !evidenceNote)}
                  style={{
                    padding: '8px 16px',
                    borderRadius: '6px',
                    backgroundColor: '#6366f1',
                    border: 'none',
                    color: '#fff',
                    fontSize: '13px',
                    fontWeight: 600,
                    cursor: 'pointer',
                  }}
                >
                  {isSubmittingEvidence ? 'Submitting...' : 'Upload Counter-Evidence'}
                </button>
              </form>
            )}
          </div>

          {/* Adjudication Controls (Admin / Ops) */}
          {(selectedDispute.status === 'OPEN' || selectedDispute.status === 'UNDER_REVIEW') && (
            <div style={{
              paddingTop: '20px',
              borderTop: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
              display: 'flex',
              gap: '12px',
            }}>
              <button
                type="button"
                onClick={() => handleResolve('WON')}
                disabled={isResolving}
                style={{
                  flex: 1,
                  padding: '12px',
                  borderRadius: '8px',
                  border: 'none',
                  backgroundColor: '#10b981',
                  color: '#fff',
                  fontWeight: 600,
                  fontSize: '13px',
                  cursor: 'pointer',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '6px',
                }}
              >
                <CheckCircle2 size={16} />
                <span>Resolve WON</span>
              </button>

              <button
                type="button"
                onClick={() => handleResolve('LOST')}
                disabled={isResolving}
                style={{
                  flex: 1,
                  padding: '12px',
                  borderRadius: '8px',
                  border: 'none',
                  backgroundColor: '#ef4444',
                  color: '#fff',
                  fontWeight: 600,
                  fontSize: '13px',
                  cursor: 'pointer',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '6px',
                }}
              >
                <XCircle size={16} />
                <span>Resolve LOST</span>
              </button>
            </div>
          )}
        </div>
      )}

      {/* Open Dispute Modal */}
      {isOpenModalActive && (
        <div style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          backgroundColor: 'rgba(0, 0, 0, 0.75)',
          backdropFilter: 'blur(4px)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 1100,
          padding: '16px',
        }}>
          <div style={{
            backgroundColor: 'var(--bg-card, #121622)',
            borderRadius: '16px',
            border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
            width: '100%',
            maxWidth: '480px',
            padding: '24px',
          }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '20px' }}>
              <h3 style={{ margin: 0, color: '#fff', fontSize: '18px' }}>Open Customer Dispute</h3>
              <button
                onClick={() => setIsOpenModalActive(false)}
                style={{ background: 'transparent', border: 'none', color: '#94a3b8', cursor: 'pointer' }}
              >
                <X size={20} />
              </button>
            </div>

            <form onSubmit={handleCreateDispute}>
              <div style={{ marginBottom: '16px' }}>
                <label style={{ display: 'block', fontSize: '13px', color: '#94a3b8', marginBottom: '6px' }}>
                  Transaction Reference
                </label>
                <input
                  type="text"
                  placeholder="e.g. TXN-20261004-RKW8IZA5"
                  value={newTxnRef}
                  onChange={(e) => setNewTxnRef(e.target.value)}
                  required
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    backgroundColor: 'rgba(255, 255, 255, 0.04)',
                    border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
                    borderRadius: '8px',
                    color: '#fff',
                    fontSize: '14px',
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div style={{ marginBottom: '24px' }}>
                <label style={{ display: 'block', fontSize: '13px', color: '#94a3b8', marginBottom: '6px' }}>
                  Dispute Reason
                </label>
                <select
                  value={newReason}
                  onChange={(e) => setNewReason(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    backgroundColor: 'rgba(255, 255, 255, 0.04)',
                    border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
                    borderRadius: '8px',
                    color: '#fff',
                    fontSize: '14px',
                    boxSizing: 'border-box',
                  }}
                >
                  <option value="CHARGEBACK_FRAUD" style={{ background: '#121622' }}>Chargeback: Suspected Fraud</option>
                  <option value="UNRECOGNIZED" style={{ background: '#121622' }}>Unrecognized Transaction</option>
                  <option value="PRODUCT_NOT_RECEIVED" style={{ background: '#121622' }}>Product / Order Not Received</option>
                  <option value="SERVICE_DEFECTIVE" style={{ background: '#121622' }}>Service Defective / Unsatisfactory</option>
                </select>
              </div>

              <div style={{ display: 'flex', gap: '12px', justifyContent: 'flex-end' }}>
                <button
                  type="button"
                  onClick={() => setIsOpenModalActive(false)}
                  style={{
                    padding: '10px 16px',
                    backgroundColor: 'transparent',
                    border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
                    borderRadius: '8px',
                    color: '#94a3b8',
                    cursor: 'pointer',
                  }}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isCreatingDispute}
                  style={{
                    padding: '10px 18px',
                    backgroundColor: '#6366f1',
                    border: 'none',
                    borderRadius: '8px',
                    color: '#fff',
                    fontWeight: 600,
                    cursor: 'pointer',
                  }}
                >
                  {isCreatingDispute ? 'Opening Dispute...' : 'Initiate Dispute'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};
