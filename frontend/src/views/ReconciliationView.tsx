import React, { useState, useEffect } from 'react';
import { GitCompare, FileText, Check, AlertTriangle, RefreshCw, Play, Eye, X, ShieldAlert, Sparkles, Send } from 'lucide-react';
import { apiService } from '../services/api';
import type { ReconciliationRunItem, ReconciliationExceptionItem } from '../types';

interface ReconciliationViewProps {
  runs?: ReconciliationRunItem[];
  apiKey?: string;
}

export const ReconciliationView: React.FC<ReconciliationViewProps> = ({
  runs: initialRuns = [],
  apiKey = 'tiq_live_pub_Fp6Wc50bYe6sFAt081ewvb2z',
}) => {
  const [runs, setRuns] = useState<ReconciliationRunItem[]>(initialRuns);
  const [selectedRun, setSelectedRun] = useState<ReconciliationRunItem | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [isSimulating, setIsSimulating] = useState<boolean>(false);
  const [showUploadModal, setShowUploadModal] = useState<boolean>(false);
  const [pasteContent, setPasteContent] = useState<string>('');
  const [pasteProvider, setPasteProvider] = useState<string>('SIMULATED_GATEWAY');
  const [resolvingException, setResolvingException] = useState<ReconciliationExceptionItem | null>(null);
  const [resolutionAction, setResolutionAction] = useState<string>('RESOLVED');
  const [resolutionNotes, setResolutionNotes] = useState<string>('');
  const [isSubmittingResolution, setIsSubmittingResolution] = useState<boolean>(false);
  const [notification, setNotification] = useState<{ text: string; type: 'success' | 'info' | 'error' } | null>(null);

  const loadRuns = async () => {
    setIsLoading(true);
    try {
      const data = await apiService.getReconciliationRuns(apiKey);
      if (data && data.length > 0) {
        setRuns(data);
      }
    } catch (err: any) {
      console.warn('Failed to load live reconciliation runs:', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadRuns();
  }, [apiKey]);

  const handleSimulateAudit = async () => {
    setIsSimulating(true);
    setNotification(null);
    try {
      const res = await apiService.generateSampleClearingFile(apiKey, 'csv', true);
      if (res.status === 'success') {
        setNotification({
          type: 'success',
          text: `Simulated clearing report generated & audited! Match rate: ${res.data?.match_rate_percent}%.`,
        });
        await loadRuns();
        if (res.data) {
          setSelectedRun(res.data);
        }
      } else {
        setNotification({
          type: 'error',
          text: res.message || 'Simulation failed.',
        });
      }
    } catch (err: any) {
      setNotification({
        type: 'error',
        text: err.message || 'Failed to trigger simulated clearing audit.',
      });
    } finally {
      setIsSimulating(false);
    }
  };

  const handleProcessPastedReport = async () => {
    if (!pasteContent.trim()) {
      setNotification({ type: 'error', text: 'Please enter CSV or JSON clearing report content.' });
      return;
    }

    setIsLoading(true);
    setNotification(null);
    try {
      const res = await apiService.processReconciliation(apiKey, {
        provider: pasteProvider,
        content: pasteContent,
        date: nowYmd(),
      });

      if (res.status === 'success') {
        setNotification({
          type: 'success',
          text: res.message || 'Clearing report ingested and reconciled.',
        });
        setShowUploadModal(false);
        setPasteContent('');
        await loadRuns();
        if (res.data) {
          setSelectedRun(res.data);
        }
      } else {
        setNotification({
          type: 'error',
          text: res.message || 'Failed to process clearing report.',
        });
      }
    } catch (err: any) {
      setNotification({
        type: 'error',
        text: err.message || 'Error processing clearing report.',
      });
    } finally {
      setIsLoading(false);
    }
  };

  const handleInspectRun = async (run: ReconciliationRunItem) => {
    if (!run.id) {
      setSelectedRun(run);
      return;
    }
    try {
      const details = await apiService.getReconciliationRunDetails(apiKey, run.id);
      setSelectedRun(details);
    } catch {
      setSelectedRun(run);
    }
  };

  const handleSubmitResolution = async () => {
    if (!resolvingException) return;
    setIsSubmittingResolution(true);
    try {
      const res = await apiService.resolveReconciliationException(
        apiKey,
        resolvingException.id,
        resolutionAction,
        resolutionNotes
      );

      if (res.status === 'success') {
        setNotification({
          type: 'success',
          text: `Exception transitioned to ${resolutionAction}.`,
        });
        setResolvingException(null);
        setResolutionNotes('');

        // Refresh selected run details
        if (selectedRun?.id) {
          const updated = await apiService.getReconciliationRunDetails(apiKey, selectedRun.id);
          setSelectedRun(updated);
        }
        await loadRuns();
      } else {
        setNotification({
          type: 'error',
          text: res.message || 'Failed to resolve exception.',
        });
      }
    } catch (err: any) {
      setNotification({
        type: 'error',
        text: err.message || 'Error saving resolution.',
      });
    } finally {
      setIsSubmittingResolution(false);
    }
  };

  // Metrics
  const totalRuns = runs.length;
  const totalMatchedVol = runs.reduce((acc, r) => acc + (r.matched_volume_minor || 0), 0);
  const totalExceptions = runs.reduce((acc, r) => acc + (r.mismatched_records || 0), 0);
  const avgMatchRate = totalRuns > 0
    ? Math.round(runs.reduce((acc, r) => acc + (r.match_rate_percent || 0), 0) / totalRuns)
    : 100;

  function nowYmd(): string {
    return new Date().toISOString().split('T')[0];
  }

  return (
    <div className="animate-fade-in" style={{ padding: '32px', display: 'flex', flexDirection: 'column', gap: '28px' }}>
      {/* Header Banner */}
      <div style={{
        background: 'linear-gradient(135deg, rgba(245, 158, 11, 0.08) 0%, rgba(99, 102, 241, 0.06) 100%)',
        border: '1px solid var(--warning-border)',
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
            background: 'rgba(245, 158, 11, 0.15)',
            color: 'var(--warning)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}>
            <GitCompare size={24} />
          </div>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <h3 style={{ fontSize: '18px', fontWeight: 700, color: '#fff' }}>
                Multi-Source Automated Reconciliation
              </h3>
              <span className="badge badge-warning" style={{ fontSize: '10px' }}>
                Two-Way Matching
              </span>
            </div>
            <p style={{ fontSize: '13px', color: 'var(--text-secondary)', marginTop: '3px' }}>
              Compares internal payment transactions against external gateway clearing files and classifies exceptions.
            </p>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }}>
          <button
            onClick={handleSimulateAudit}
            disabled={isSimulating}
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
            <Sparkles size={15} className={isSimulating ? 'animate-spin' : ''} />
            <span>{isSimulating ? 'Running Audit...' : 'Run Simulated Clearing Audit'}</span>
          </button>

          <button
            onClick={() => setShowUploadModal(true)}
            className="btn btn-secondary"
            style={{ padding: '10px 14px', fontSize: '13px', display: 'flex', alignItems: 'center', gap: '6px' }}
          >
            <FileText size={15} />
            <span>Ingest Clearing File</span>
          </button>

          <button
            onClick={loadRuns}
            disabled={isLoading}
            className="btn btn-secondary"
            style={{ padding: '10px 14px', fontSize: '13px', display: 'flex', alignItems: 'center', gap: '6px' }}
          >
            <RefreshCw size={14} className={isLoading ? 'animate-spin' : ''} />
            <span>Refresh</span>
          </button>
        </div>
      </div>

      {/* Notification Banner */}
      {notification && (
        <div style={{
          padding: '14px 18px',
          borderRadius: 'var(--radius-md)',
          fontSize: '13px',
          display: 'flex',
          alignItems: 'center',
          gap: '10px',
          background:
            notification.type === 'success'
              ? 'var(--success-bg)'
              : notification.type === 'error'
              ? 'var(--danger-bg)'
              : 'var(--info-bg)',
          border: '1px solid',
          borderColor:
            notification.type === 'success'
              ? 'var(--success-border)'
              : notification.type === 'error'
              ? 'var(--danger-border)'
              : 'var(--info-border)',
          color:
            notification.type === 'success'
              ? 'var(--success)'
              : notification.type === 'error'
              ? 'var(--danger)'
              : 'var(--info)',
        }}>
          {notification.type === 'success' && <Check size={16} />}
          {notification.type === 'error' && <AlertTriangle size={16} />}
          {notification.type === 'info' && <Play size={16} />}
          <span>{notification.text}</span>
        </div>
      )}

      {/* Metrics Row */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '16px' }}>
        <div className="card" style={{ padding: '18px 20px' }}>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary)', fontWeight: 600, textTransform: 'uppercase' }}>
            Audited Batch Runs
          </div>
          <div className="mono" style={{ fontSize: '20px', fontWeight: 800, color: '#fff', marginTop: '8px' }}>
            {totalRuns} Runs
          </div>
          <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
            Across all acquiring gateways
          </div>
        </div>

        <div className="card" style={{ padding: '18px 20px' }}>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary)', fontWeight: 600, textTransform: 'uppercase' }}>
            Average Match Rate
          </div>
          <div className="mono" style={{ fontSize: '20px', fontWeight: 800, color: avgMatchRate >= 95 ? 'var(--success)' : 'var(--warning)', marginTop: '8px' }}>
            {avgMatchRate}%
          </div>
          <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
            Deterministic two-way matching
          </div>
        </div>

        <div className="card" style={{ padding: '18px 20px' }}>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary)', fontWeight: 600, textTransform: 'uppercase' }}>
            Matched Cleared Volume
          </div>
          <div className="mono" style={{ fontSize: '20px', fontWeight: 800, color: 'var(--info)', marginTop: '8px' }}>
            {apiService.formatMoney(totalMatchedVol)}
          </div>
          <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
            Reconciled with upstream providers
          </div>
        </div>

        <div className="card" style={{ padding: '18px 20px' }}>
          <div style={{ fontSize: '12px', color: 'var(--text-secondary)', fontWeight: 600, textTransform: 'uppercase' }}>
            Discrepancies Flagged
          </div>
          <div className="mono" style={{ fontSize: '20px', fontWeight: 800, color: totalExceptions > 0 ? 'var(--danger)' : '#fff', marginTop: '8px' }}>
            {totalExceptions} Items
          </div>
          <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '4px' }}>
            {totalExceptions > 0 ? 'Exceptions categorized in matrix' : 'Zero discrepancies'}
          </div>
        </div>
      </div>

      {/* Batch Reconciliation Runs Table */}
      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <div style={{
          padding: '20px 24px',
          borderBottom: '1px solid var(--border-subtle)',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          flexWrap: 'wrap',
          gap: '12px',
        }}>
          <div>
            <h4 style={{ fontSize: '15px', fontWeight: 700, color: '#fff' }}>
              Reconciliation Batch Audit Runs ({runs.length})
            </h4>
            <p style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
              Historical clearing files audited against internal database
            </p>
          </div>
        </div>

        <table className="data-table">
          <thead>
            <tr>
              <th>Run Reference</th>
              <th>Provider / Rail</th>
              <th>Audit Date</th>
              <th>Internal Records</th>
              <th>Provider Records</th>
              <th>Matched</th>
              <th>Discrepancies</th>
              <th>Match Rate</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {runs.length === 0 ? (
              <tr>
                <td colSpan={10} style={{ textAlign: 'center', padding: '36px', color: 'var(--text-muted)' }}>
                  No reconciliation runs yet. Click "Run Simulated Clearing Audit" above to test the engine!
                </td>
              </tr>
            ) : (
              runs.map((r) => {
                const rate = r.match_rate_percent ?? 100;
                return (
                  <tr key={r.id || r.run_reference}>
                    <td className="mono" style={{ fontWeight: 600, color: '#fff' }}>
                      {r.run_reference}
                    </td>
                    <td>
                      <span className="badge badge-neutral" style={{ fontSize: '11px' }}>{r.provider}</span>
                    </td>
                    <td>{r.reconciliation_date}</td>
                    <td className="mono">{r.total_internal_records}</td>
                    <td className="mono">{r.total_provider_records}</td>
                    <td className="mono" style={{ color: 'var(--success)', fontWeight: 600 }}>
                      {r.matched_records}
                    </td>
                    <td className="mono" style={{ color: r.mismatched_records > 0 ? 'var(--danger)' : 'var(--text-muted)', fontWeight: r.mismatched_records > 0 ? 700 : 400 }}>
                      {r.mismatched_records}
                    </td>
                    <td>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <div style={{ flex: 1, height: '6px', background: 'rgba(255,255,255,0.08)', borderRadius: '3px', overflow: 'hidden', width: '80px' }}>
                          <div style={{ width: `${rate}%`, height: '100%', background: rate >= 90 ? 'var(--success)' : rate >= 70 ? 'var(--warning)' : 'var(--danger)' }} />
                        </div>
                        <span className="mono" style={{ fontSize: '11px', color: '#fff' }}>{rate}%</span>
                      </div>
                    </td>
                    <td>
                      <span className="badge badge-success" style={{ fontSize: '10px' }}>
                        <Check size={11} />
                        <span>{r.status}</span>
                      </span>
                    </td>
                    <td>
                      <button
                        onClick={() => handleInspectRun(r)}
                        className="btn btn-secondary"
                        style={{ padding: '4px 10px', fontSize: '11px', display: 'flex', alignItems: 'center', gap: '4px' }}
                      >
                        <Eye size={12} />
                        <span>Inspect Exceptions</span>
                      </button>
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {/* Discrepancy Matrix Inspection Modal */}
      {selectedRun && (
        <div style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          background: 'rgba(0, 0, 0, 0.8)',
          backdropFilter: 'blur(5px)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 9999,
          padding: '24px',
        }}>
          <div className="card animate-fade-in" style={{
            maxWidth: '860px',
            width: '100%',
            maxHeight: '90vh',
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
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                  <h4 style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
                    Reconciliation Run: {selectedRun.run_reference}
                  </h4>
                  <span className="badge badge-info" style={{ fontSize: '10px' }}>
                    {selectedRun.provider}
                  </span>
                </div>
                <p style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '2px' }}>
                  Clearing Date: {selectedRun.reconciliation_date} • Match Rate: {selectedRun.match_rate_percent}%
                </p>
              </div>
              <button
                onClick={() => setSelectedRun(null)}
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
              {/* Run Metrics Bar */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '12px' }}>
                <div style={{ padding: '12px', background: 'rgba(0,0,0,0.3)', borderRadius: 'var(--radius-sm)' }}>
                  <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Internal Records</div>
                  <div className="mono" style={{ fontSize: '16px', fontWeight: 700, color: '#fff', marginTop: '4px' }}>
                    {selectedRun.total_internal_records} txns
                  </div>
                </div>
                <div style={{ padding: '12px', background: 'rgba(0,0,0,0.3)', borderRadius: 'var(--radius-sm)' }}>
                  <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Provider Records</div>
                  <div className="mono" style={{ fontSize: '16px', fontWeight: 700, color: '#fff', marginTop: '4px' }}>
                    {selectedRun.total_provider_records} records
                  </div>
                </div>
                <div style={{ padding: '12px', background: 'rgba(0,0,0,0.3)', borderRadius: 'var(--radius-sm)' }}>
                  <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Matched Volume</div>
                  <div className="mono" style={{ fontSize: '16px', fontWeight: 700, color: 'var(--success)', marginTop: '4px' }}>
                    {apiService.formatMoney(selectedRun.matched_volume_minor || 0)}
                  </div>
                </div>
                <div style={{ padding: '12px', background: 'rgba(0,0,0,0.3)', borderRadius: 'var(--radius-sm)' }}>
                  <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Discrepancies</div>
                  <div className="mono" style={{ fontSize: '16px', fontWeight: 700, color: selectedRun.mismatched_records > 0 ? 'var(--danger)' : '#fff', marginTop: '4px' }}>
                    {selectedRun.mismatched_records} items
                  </div>
                </div>
              </div>

              {/* Exceptions Matrix */}
              <div>
                <h5 style={{ fontSize: '13px', fontWeight: 700, color: '#fff', marginBottom: '10px', display: 'flex', alignItems: 'center', gap: '8px' }}>
                  <ShieldAlert size={14} color="var(--warning)" />
                  <span>Flagged Exceptions ({selectedRun.exceptions?.length || selectedRun.mismatched_records || 0})</span>
                </h5>

                <div style={{ border: '1px solid var(--border-subtle)', borderRadius: 'var(--radius-sm)', overflow: 'hidden' }}>
                  <table className="data-table" style={{ fontSize: '12px' }}>
                    <thead>
                      <tr>
                        <th>Exception Type</th>
                        <th>Internal vs Provider Reference</th>
                        <th>Internal Amount</th>
                        <th>Provider Amount</th>
                        <th>Status Discrepancy</th>
                        <th>Workflow Status</th>
                        <th>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(!selectedRun.exceptions || selectedRun.exceptions.length === 0) ? (
                        <tr>
                          <td colSpan={7} style={{ textAlign: 'center', padding: '24px', color: 'var(--text-muted)' }}>
                            No exceptions recorded. 100% clean matching for this clearing run!
                          </td>
                        </tr>
                      ) : (
                        selectedRun.exceptions.map((exc) => {
                          const isMissingInternal = exc.exception_type === 'MISSING_IN_INTERNAL';
                          const isMissingProvider = exc.exception_type === 'MISSING_IN_PROVIDER';
                          const isAmountMismatch = exc.exception_type === 'AMOUNT_MISMATCH';

                          return (
                            <tr key={exc.id}>
                              <td>
                                <span className={`badge ${
                                  isMissingInternal ? 'badge-warning' : isMissingProvider ? 'badge-danger' : isAmountMismatch ? 'badge-warning' : 'badge-info'
                                }`} style={{ fontSize: '10px' }}>
                                  {exc.exception_type.replace(/_/g, ' ')}
                                </span>
                              </td>
                              <td className="mono" style={{ fontSize: '11px' }}>
                                <div>Int: {exc.internal_reference || '—'}</div>
                                <div style={{ color: 'var(--text-muted)' }}>Prov: {exc.provider_reference || '—'}</div>
                              </td>
                              <td className="mono">
                                {exc.internal_amount_minor != null ? apiService.formatMoney(exc.internal_amount_minor) : '—'}
                              </td>
                              <td className="mono">
                                {exc.provider_amount_minor != null ? apiService.formatMoney(exc.provider_amount_minor) : '—'}
                              </td>
                              <td>
                                {exc.internal_status && exc.provider_status ? (
                                  <div style={{ fontSize: '11px' }}>
                                    <span>{exc.internal_status}</span> &rarr; <span style={{ color: 'var(--success)' }}>{exc.provider_status}</span>
                                  </div>
                                ) : '—'}
                              </td>
                              <td>
                                <span className={`badge ${
                                  exc.status === 'RESOLVED' ? 'badge-success' : exc.status === 'INVESTIGATING' ? 'badge-info' : 'badge-warning'
                                }`} style={{ fontSize: '10px' }}>
                                  {exc.status}
                                </span>
                              </td>
                              <td>
                                {exc.status === 'OPEN' || exc.status === 'INVESTIGATING' ? (
                                  <button
                                    onClick={() => setResolvingException(exc)}
                                    className="btn btn-secondary"
                                    style={{ padding: '3px 8px', fontSize: '11px' }}
                                  >
                                    Resolve
                                  </button>
                                ) : (
                                  <span style={{ fontSize: '11px', color: 'var(--text-muted)' }}>
                                    {exc.resolution_notes ? 'Resolved' : 'Closed'}
                                  </span>
                                )}
                              </td>
                            </tr>
                          );
                        })
                      )}
                    </tbody>
                  </table>
                </div>
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
                onClick={() => setSelectedRun(null)}
                className="btn btn-secondary"
                style={{ padding: '8px 16px', fontSize: '12px' }}
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Exception Resolution Modal */}
      {resolvingException && (
        <div style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          background: 'rgba(0, 0, 0, 0.85)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 10000,
          padding: '24px',
        }}>
          <div className="card animate-fade-in" style={{ maxWidth: '520px', width: '100%', padding: '24px', display: 'flex', flexDirection: 'column', gap: '18px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <h4 style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
                Triage Reconciliation Exception
              </h4>
              <button
                onClick={() => setResolvingException(null)}
                style={{ background: 'transparent', border: 'none', color: 'var(--text-secondary)', cursor: 'pointer' }}
              >
                <X size={18} />
              </button>
            </div>

            <div style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
              <div><strong>Type:</strong> {resolvingException.exception_type}</div>
              <div><strong>Reference:</strong> {resolvingException.internal_reference || resolvingException.provider_reference}</div>
            </div>

            <div>
              <label style={{ display: 'block', fontSize: '12px', fontWeight: 600, color: '#fff', marginBottom: '6px' }}>
                Resolution Action
              </label>
              <select
                value={resolutionAction}
                onChange={(e) => setResolutionAction(e.target.value)}
                style={{
                  width: '100%',
                  padding: '8px 12px',
                  background: 'rgba(0, 0, 0, 0.3)',
                  border: '1px solid var(--border-subtle)',
                  borderRadius: 'var(--radius-sm)',
                  color: '#fff',
                  fontSize: '13px',
                }}
              >
                <option value="RESOLVED">Mark as Resolved (Standard Cleared)</option>
                <option value="FORCE_SUCCESS">Force Success & Capture to Ledger</option>
                <option value="INVESTIGATING">Keep Under Investigation</option>
                <option value="WRITTEN_OFF">Write Off Variance</option>
              </select>
            </div>

            <div>
              <label style={{ display: 'block', fontSize: '12px', fontWeight: 600, color: '#fff', marginBottom: '6px' }}>
                Resolution Audit Notes
              </label>
              <textarea
                rows={3}
                placeholder="Explain resolution rationale for financial audit trail..."
                value={resolutionNotes}
                onChange={(e) => setResolutionNotes(e.target.value)}
                style={{
                  width: '100%',
                  padding: '8px 12px',
                  background: 'rgba(0, 0, 0, 0.3)',
                  border: '1px solid var(--border-subtle)',
                  borderRadius: 'var(--radius-sm)',
                  color: '#fff',
                  fontSize: '12px',
                  fontFamily: 'inherit',
                }}
              />
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
              <button
                onClick={() => setResolvingException(null)}
                className="btn btn-secondary"
                style={{ padding: '8px 14px', fontSize: '12px' }}
              >
                Cancel
              </button>
              <button
                onClick={handleSubmitResolution}
                disabled={isSubmittingResolution}
                className="btn btn-primary"
                style={{ padding: '8px 16px', fontSize: '12px', display: 'flex', alignItems: 'center', gap: '6px' }}
              >
                <Send size={13} />
                <span>{isSubmittingResolution ? 'Saving...' : 'Save Resolution'}</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Upload / Ingest Report Modal */}
      {showUploadModal && (
        <div style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          background: 'rgba(0, 0, 0, 0.8)',
          backdropFilter: 'blur(5px)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 9999,
          padding: '24px',
        }}>
          <div className="card animate-fade-in" style={{ maxWidth: '640px', width: '100%', padding: '24px', display: 'flex', flexDirection: 'column', gap: '18px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <h4 style={{ fontSize: '16px', fontWeight: 700, color: '#fff' }}>
                Ingest External Clearing Report
              </h4>
              <button
                onClick={() => setShowUploadModal(false)}
                style={{ background: 'transparent', border: 'none', color: 'var(--text-secondary)', cursor: 'pointer' }}
              >
                <X size={18} />
              </button>
            </div>

            <p style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
              Paste raw CSV or JSON settlement statements received from payment gateways or clearing houses.
            </p>

            <div>
              <label style={{ display: 'block', fontSize: '12px', fontWeight: 600, color: '#fff', marginBottom: '6px' }}>
                Acquiring Gateway / Provider
              </label>
              <select
                value={pasteProvider}
                onChange={(e) => setPasteProvider(e.target.value)}
                style={{
                  width: '100%',
                  padding: '8px 12px',
                  background: 'rgba(0, 0, 0, 0.3)',
                  border: '1px solid var(--border-subtle)',
                  borderRadius: 'var(--radius-sm)',
                  color: '#fff',
                  fontSize: '13px',
                }}
              >
                <option value="SIMULATED_GATEWAY">Simulated Payment Gateway</option>
                <option value="PAYSTACK">Paystack Payments</option>
                <option value="FLUTTERWAVE">Flutterwave Technology</option>
                <option value="INTERSWITCH">Interswitch / WebPAY</option>
                <option value="NIBSS">NIBSS Instant Settlement</option>
              </select>
            </div>

            <div>
              <label style={{ display: 'block', fontSize: '12px', fontWeight: 600, color: '#fff', marginBottom: '6px' }}>
                Clearing Data (CSV or JSON)
              </label>
              <textarea
                rows={8}
                placeholder="provider_reference,transaction_reference,amount,fee,status&#10;PROV-SETTLE-001,TXN-20260923-001,10000.00,150.00,SUCCESS"
                value={pasteContent}
                onChange={(e) => setPasteContent(e.target.value)}
                style={{
                  width: '100%',
                  padding: '10px 12px',
                  background: 'rgba(0, 0, 0, 0.35)',
                  border: '1px solid var(--border-subtle)',
                  borderRadius: 'var(--radius-sm)',
                  color: '#fff',
                  fontSize: '12px',
                  fontFamily: 'var(--font-mono)',
                }}
              />
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
              <button
                onClick={() => setShowUploadModal(false)}
                className="btn btn-secondary"
                style={{ padding: '8px 14px', fontSize: '12px' }}
              >
                Cancel
              </button>
              <button
                onClick={handleProcessPastedReport}
                disabled={isLoading}
                className="btn btn-primary"
                style={{ padding: '8px 16px', fontSize: '12px', display: 'flex', alignItems: 'center', gap: '6px' }}
              >
                <Play size={13} />
                <span>{isLoading ? 'Reconciling...' : 'Run Reconciliation Audit'}</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
