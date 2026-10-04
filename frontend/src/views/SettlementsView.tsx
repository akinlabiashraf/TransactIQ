import React, { useState, useEffect } from 'react';
import { Landmark, Check, Clock, AlertTriangle, RefreshCw, Send, Eye, X } from 'lucide-react';
import { apiService } from '../services/api';
import type { Settlement } from '../types';

interface SettlementsViewProps {
  apiKey?: string;
}

export const SettlementsView: React.FC<SettlementsViewProps> = ({
  apiKey = 'tiq_live_pub_Fp6Wc50bYe6sFAt081ewvb2z',
}) => {
  const [settlements, setSettlements] = useState<Settlement[]>([]);
  const [selectedStatus, setSelectedStatus] = useState<string>('ALL');
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [isGenerating, setIsGenerating] = useState<boolean>(false);
  const [completingId, setCompletingId] = useState<string | null>(null);
  const [selectedSettlement, setSelectedSettlement] = useState<Settlement | null>(null);
  const [actionMessage, setActionMessage] = useState<{ text: string; type: 'success' | 'info' | 'error' } | null>(null);

  const loadSettlements = async () => {
    setIsLoading(true);
    try {
      const data = await apiService.getSettlements(apiKey, selectedStatus);
      setSettlements(data);
    } catch (err: any) {
      console.warn('Failed to load settlements:', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadSettlements();
  }, [apiKey, selectedStatus]);

  const handleGenerateBatch = async () => {
    setIsGenerating(true);
    setActionMessage(null);
    try {
      const res = await apiService.generateSettlementBatch(apiKey, 'NGN');
      if (res.status === 'noop') {
        setActionMessage({
          type: 'info',
          text: res.message || 'No eligible cleared transactions awaiting settlement.',
        });
      } else if (res.status === 'success') {
        setActionMessage({
          type: 'success',
          text: res.message || 'Settlement batch generated successfully!',
        });
        await loadSettlements();
      } else {
        setActionMessage({
          type: 'error',
          text: res.message || 'Failed to generate settlement batch.',
        });
      }
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err.message || 'Error executing settlement batch engine.',
      });
    } finally {
      setIsGenerating(false);
    }
  };

  const handleCompletePayout = async (id: string) => {
    setCompletingId(id);
    setActionMessage(null);
    try {
      const payoutRef = `WIRE-ZENITH-${Date.now().toString().slice(-6)}`;
      const res = await apiService.completeSettlementPayout(apiKey, id, payoutRef);
      if (res.status === 'success') {
        setActionMessage({
          type: 'success',
          text: `Settlement fulfilled via payout wire [${payoutRef}]. Webhook settlement.completed dispatched.`,
        });
        await loadSettlements();
      } else {
        setActionMessage({
          type: 'error',
          text: res.message || 'Failed to fulfill settlement payout.',
        });
      }
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err.message || 'Wire payout fulfillment failed.',
      });
    } finally {
      setCompletingId(null);
    }
  };

  const openDetails = async (settlement: Settlement) => {
    try {
      const details = await apiService.getSettlementDetails(apiKey, settlement.id);
      setSelectedSettlement(details);
    } catch {
      setSelectedSettlement(settlement);
    }
  };

  // Metrics
  const totalGross = settlements.reduce((sum, s) => sum + (s.gross_amount || 0), 0);
  const totalFees = settlements.reduce((sum, s) => sum + (s.fee_amount || 0), 0);
  const totalNet = settlements.reduce((sum, s) => sum + (s.net_amount || 0), 0);
  const pendingCount = settlements.filter(s => s.status === 'PENDING').length;

  return (
    <div className="animate-fade-in" style={{ padding: '32px', display: 'flex', flexDirection: 'column', gap: '28px' }}>
      {/* Settlements Header */}
      <div style={{
        background: 'linear-gradient(135deg, rgba(16, 185, 129, 0.08) 0%, rgba(6, 182, 212, 0.06) 100%)',
        border: '1px solid var(--success-border)',
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
            background: 'rgba(16, 185, 129, 0.15)',
            color: 'var(--success)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}>
            <Landmark size={24} />
          </div>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <h3 style={{ fontSize: '18px', fontWeight: 700, color: '#fff' }}>
                Automated Merchant Settlement Engine
              </h3>
              <span className="badge badge-success" style={{ fontSize: '10px' }}>
                T+1 Automated
              </span>
            </div>
            <p style={{ fontSize: '13px', color: 'var(--text-secondary)', marginTop: '3px' }}>
              Groups cleared transactions, deducts merchant fee schedules, isolates funds in escrow, and fulfills bank payouts.
            </p>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <button
            onClick={handleGenerateBatch}
            disabled={isGenerating}
            className="btn btn-primary"
            style={{
              padding: '10px 18px',
              fontSize: '13px',
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              boxShadow: 'var(--shadow-glow)',
            }}
          >
            <Clock size={15} className={isGenerating ? 'animate-spin' : ''} />
            <span>{isGenerating ? 'Sweeping Cleared Txns...' : 'Generate Settlement Batch (T+1)'}</span>
          </button>

          <button
            onClick={loadSettlements}
            disabled={isLoading}
            className="btn btn-secondary"
            style={{ padding: '10px 14px', fontSize: '13px', display: 'flex', alignItems: 'center', gap: '6px' }}
          >
            <RefreshCw size={14} className={isLoading ? 'animate-spin' : ''} />
            <span>Refresh</span>
          </button>
        </div>
      </div>

      {/* Action Notification */}
      {actionMessage && (
        <div style={{
          padding: '14px 18px',
          borderRadius: 'var(--radius-md)',
          fontSize: '13px',
          display: 'flex',
          alignItems: 'center',
          gap: '10px',
          background:
            actionMessage.type === 'success'
              ? 'var(--success-bg)'
              : actionMessage.type === 'error'
              ? 'var(--danger-bg)'
              : 'var(--info-bg)',
          border: '1px solid',
          borderColor:
            actionMessage.type === 'success'
              ? 'var(--success-border)'
              : actionMessage.type === 'error'
              ? 'var(--danger-border)'
              : 'var(--info-border)',
          color:
            actionMessage.type === 'success'
              ? 'var(--success)'
              : actionMessage.type === 'error'
              ? 'var(--danger)'
              : 'var(--info)',
        }}>
          {actionMessage.type === 'success' && <Check size={16} />}
          {actionMessage.type === 'error' && <AlertTriangle size={16} />}
          {actionMessage.type === 'info' && <Clock size={16} />}
          <span>{actionMessage.text}</span>
        </div>
      )}

      {/* Metrics Row */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '16px' }}>
        <div className="card" style={{ padding: '18px 20px' }}>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary)', fontWeight: 600, textTransform: 'uppercase' }}>
            Gross Cleared Volume
          </div>
          <div className="mono" style={{ fontSize: '20px', fontWeight: 800, color: '#fff', marginTop: '8px' }}>
            {apiService.formatMoney(totalGross)}
          </div>
          <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
            From {settlements.length} settlement batches
          </div>
        </div>

        <div className="card" style={{ padding: '18px 20px' }}>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary)', fontWeight: 600, textTransform: 'uppercase' }}>
            Platform Fee Deductions
          </div>
          <div className="mono" style={{ fontSize: '20px', fontWeight: 800, color: 'var(--info)', marginTop: '8px' }}>
            {apiService.formatMoney(totalFees)}
          </div>
          <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
            Retained in platform revenue ledger
          </div>
        </div>

        <div className="card" style={{ padding: '18px 20px' }}>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary)', fontWeight: 600, textTransform: 'uppercase' }}>
            Net Merchant Payouts
          </div>
          <div className="mono" style={{ fontSize: '20px', fontWeight: 800, color: 'var(--success)', marginTop: '8px' }}>
            {apiService.formatMoney(totalNet)}
          </div>
          <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
            Disbursed to merchant bank account
          </div>
        </div>

        <div className="card" style={{ padding: '18px 20px' }}>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary)', fontWeight: 600, textTransform: 'uppercase' }}>
            Pending Settlements
          </div>
          <div className="mono" style={{ fontSize: '20px', fontWeight: 800, color: pendingCount > 0 ? 'var(--warning)' : '#fff', marginTop: '8px' }}>
            {pendingCount} Batches
          </div>
          <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
            {pendingCount > 0 ? 'Awaiting wire payout release' : 'All batches fulfilled'}
          </div>
        </div>
      </div>

      {/* Settlements Table Card */}
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
              Settlement Batches & Wire Payouts
            </h4>
            <p style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
              Payout history for SwiftPay Retail Enterprises (Zenith Bank 1012345678)
            </p>
          </div>

          {/* Status Filter */}
          <div style={{ display: 'flex', gap: '6px' }}>
            {['ALL', 'PENDING', 'PROCESSING', 'COMPLETED', 'FAILED'].map((st) => (
              <button
                key={st}
                onClick={() => setSelectedStatus(st)}
                style={{
                  padding: '4px 10px',
                  borderRadius: 'var(--radius-sm)',
                  fontSize: '11px',
                  fontWeight: 600,
                  cursor: 'pointer',
                  background: selectedStatus === st ? 'var(--accent-primary)' : 'rgba(255, 255, 255, 0.05)',
                  color: selectedStatus === st ? '#fff' : 'var(--text-secondary)',
                  border: '1px solid',
                  borderColor: selectedStatus === st ? 'var(--accent-primary)' : 'var(--border-subtle)',
                }}
              >
                {st}
              </button>
            ))}
          </div>
        </div>

        <table className="data-table">
          <thead>
            <tr>
              <th>Settlement Reference</th>
              <th>Destination Bank Account</th>
              <th>Txn Count</th>
              <th>Gross Volume</th>
              <th>Platform Fee</th>
              <th>Net Payout</th>
              <th>Status</th>
              <th>Payout Reference</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {settlements.length === 0 ? (
              <tr>
                <td colSpan={9} style={{ textAlign: 'center', padding: '36px', color: 'var(--text-muted)' }}>
                  No settlement batches found. Click "Generate Settlement Batch (T+1)" above to sweep cleared transactions!
                </td>
              </tr>
            ) : (
              settlements.map((s) => (
                <tr key={s.id || s.settlement_reference}>
                  <td className="mono" style={{ fontWeight: 600, color: '#fff' }}>
                    {s.settlement_reference}
                  </td>
                  <td style={{ fontSize: '12px' }}>
                    {s.settlement_bank || 'Zenith Bank PLC'}
                    <span className="mono" style={{ color: 'var(--text-muted)', display: 'block', fontSize: '11px' }}>
                      {s.settlement_account || '1012345678'}
                    </span>
                  </td>
                  <td className="mono" style={{ textAlign: 'center' }}>
                    {s.transaction_count}
                  </td>
                  <td className="mono" style={{ fontWeight: 600, color: '#fff' }}>
                    {apiService.formatMoney(s.gross_amount, s.currency)}
                  </td>
                  <td className="mono" style={{ color: 'var(--info)', fontSize: '12px' }}>
                    {apiService.formatMoney(s.fee_amount, s.currency)}
                  </td>
                  <td className="mono" style={{ fontWeight: 700, color: 'var(--success)' }}>
                    {apiService.formatMoney(s.net_amount, s.currency)}
                  </td>
                  <td>
                    <span className={`badge ${
                      s.status === 'COMPLETED'
                        ? 'badge-success'
                        : s.status === 'PENDING'
                        ? 'badge-warning'
                        : 'badge-neutral'
                    }`} style={{ fontSize: '10px' }}>
                      {s.status === 'COMPLETED' && <Check size={11} />}
                      {s.status === 'PENDING' && <Clock size={11} />}
                      <span>{s.status}</span>
                    </span>
                  </td>
                  <td className="mono" style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>
                    {s.payout_reference || '—'}
                  </td>
                  <td>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                      {s.status === 'PENDING' && (
                        <button
                          onClick={() => handleCompletePayout(s.id)}
                          disabled={completingId === s.id}
                          className="btn btn-primary"
                          style={{ padding: '4px 10px', fontSize: '11px', display: 'flex', alignItems: 'center', gap: '4px' }}
                        >
                          <Send size={11} />
                          <span>{completingId === s.id ? 'Disbursing...' : 'Disburse Wire'}</span>
                        </button>
                      )}
                      <button
                        onClick={() => openDetails(s)}
                        className="btn btn-secondary"
                        style={{ padding: '4px 8px', fontSize: '11px', display: 'flex', alignItems: 'center', gap: '4px' }}
                      >
                        <Eye size={12} />
                        <span>Inspect</span>
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Settlement Batch Inspection Modal */}
      {selectedSettlement && (
        <div style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          background: 'rgba(0, 0, 0, 0.75)',
          backdropFilter: 'blur(4px)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 9999,
          padding: '24px',
        }}>
          <div className="card animate-fade-in" style={{
            maxWidth: '720px',
            width: '100%',
            maxHeight: '85vh',
            display: 'flex',
            flexDirection: 'column',
            padding: 0,
            overflow: 'hidden',
          }}>
            {/* Modal Header */}
            <div style={{
              padding: '20px 24px',
              borderBottom: '1px solid var(--border-subtle)',
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
            }}>
              <div>
                <h4 style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
                  Settlement Batch: {selectedSettlement.settlement_reference}
                </h4>
                <p style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
                  Batch Status: {selectedSettlement.status}
                </p>
              </div>
              <button
                onClick={() => setSelectedSettlement(null)}
                style={{
                  background: 'transparent',
                  border: 'none',
                  color: 'var(--text-secondary)',
                  cursor: 'pointer',
                  padding: '4px',
                }}
              >
                <X size={20} />
              </button>
            </div>

            {/* Modal Body */}
            <div style={{ padding: '24px', overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '20px' }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '12px' }}>
                <div style={{ padding: '12px', background: 'rgba(0,0,0,0.3)', borderRadius: 'var(--radius-sm)' }}>
                  <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Gross Amount</div>
                  <div className="mono" style={{ fontSize: '16px', fontWeight: 700, color: '#fff', marginTop: '4px' }}>
                    {apiService.formatMoney(selectedSettlement.gross_amount, selectedSettlement.currency)}
                  </div>
                </div>
                <div style={{ padding: '12px', background: 'rgba(0,0,0,0.3)', borderRadius: 'var(--radius-sm)' }}>
                  <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Platform Fee</div>
                  <div className="mono" style={{ fontSize: '16px', fontWeight: 700, color: 'var(--info)', marginTop: '4px' }}>
                    {apiService.formatMoney(selectedSettlement.fee_amount, selectedSettlement.currency)}
                  </div>
                </div>
                <div style={{ padding: '12px', background: 'rgba(0,0,0,0.3)', borderRadius: 'var(--radius-sm)' }}>
                  <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Net Disbursed</div>
                  <div className="mono" style={{ fontSize: '16px', fontWeight: 700, color: 'var(--success)', marginTop: '4px' }}>
                    {apiService.formatMoney(selectedSettlement.net_amount, selectedSettlement.currency)}
                  </div>
                </div>
              </div>

              {/* Transactions in Batch */}
              <div>
                <h5 style={{ fontSize: '13px', fontWeight: 700, color: '#fff', marginBottom: '10px' }}>
                  Transactions In Batch ({selectedSettlement.transactions?.length || selectedSettlement.transaction_count || 0})
                </h5>
                <div style={{ maxHeight: '220px', overflowY: 'auto', border: '1px solid var(--border-subtle)', borderRadius: 'var(--radius-sm)' }}>
                  <table className="data-table" style={{ fontSize: '12px' }}>
                    <thead>
                      <tr>
                        <th>Reference</th>
                        <th>Gross</th>
                        <th>Fee</th>
                        <th>Net</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedSettlement.transactions && selectedSettlement.transactions.length > 0 ? (
                        selectedSettlement.transactions.map((t) => (
                          <tr key={t.id || t.reference}>
                            <td className="mono">{t.reference}</td>
                            <td className="mono">{apiService.formatMoney(t.amount, t.currency)}</td>
                            <td className="mono" style={{ color: 'var(--info)' }}>{apiService.formatMoney(t.fee_amount, t.currency)}</td>
                            <td className="mono" style={{ color: 'var(--success)' }}>{apiService.formatMoney(t.net_amount, t.currency)}</td>
                            <td>
                              <span className="badge badge-success" style={{ fontSize: '9px' }}>{t.status}</span>
                            </td>
                          </tr>
                        ))
                      ) : (
                        <tr>
                          <td colSpan={5} style={{ textAlign: 'center', padding: '16px', color: 'var(--text-muted)' }}>
                            {selectedSettlement.transaction_count} cleared transactions aggregated in this settlement payout batch.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>

              {/* Payout & Timing metadata */}
              <div style={{ fontSize: '12px', color: 'var(--text-secondary)', display: 'flex', flexDirection: 'column', gap: '6px' }}>
                <div><strong>Destination:</strong> {selectedSettlement.settlement_bank} ({selectedSettlement.settlement_account})</div>
                <div><strong>Wire Reference:</strong> <span className="mono">{selectedSettlement.payout_reference || 'Pending Payout Execution'}</span></div>
                <div><strong>Initiated At:</strong> {selectedSettlement.initiated_at ? new Date(selectedSettlement.initiated_at).toLocaleString() : 'N/A'}</div>
                <div><strong>Completed At:</strong> {selectedSettlement.completed_at ? new Date(selectedSettlement.completed_at).toLocaleString() : 'Not Yet Completed'}</div>
              </div>
            </div>

            {/* Modal Footer */}
            <div style={{
              padding: '16px 24px',
              borderTop: '1px solid var(--border-subtle)',
              display: 'flex',
              justifyContent: 'flex-end',
            }}>
              <button
                onClick={() => setSelectedSettlement(null)}
                className="btn btn-secondary"
                style={{ padding: '8px 16px', fontSize: '12px' }}
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
