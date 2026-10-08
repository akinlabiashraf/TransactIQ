import React, { useState, useEffect } from 'react';
import {
  ShieldAlert,
  ShieldCheck,
  Lock,
  Key,
  Users,
  RefreshCw,
  Eye,
  X,
  Play,
  CheckCircle2,
  FileText,
  Activity,
  Cpu,
  BrainCircuit,
} from 'lucide-react';
import { apiService } from '../services/api';
import type {
  AuditLogItem,
  AuditChainStatus,
  RiskMetrics,
  RolePolicyItem,
  RiskSimulationResult,
} from '../types';

interface SecurityViewProps {
  apiKey?: string;
}

export const SecurityView: React.FC<SecurityViewProps> = ({
  apiKey = 'tiq_live_sec_SWIFTPAY_PROD_99887766554433221100',
}) => {
  const [activeTab, setActiveTab] = useState<'audit' | 'risk' | 'rbac'>('audit');

  // Audit Logs State
  const [auditLogs, setAuditLogs] = useState<AuditLogItem[]>([]);
  const [chainStatus, setChainStatus] = useState<AuditChainStatus | null>(null);
  const [selectedAction, setSelectedAction] = useState<string>('ALL');
  const [selectedActor, setSelectedActor] = useState<string>('ALL');
  const [selectedLog, setSelectedLog] = useState<AuditLogItem | null>(null);
  const [isVerifyingChain, setIsVerifyingChain] = useState<boolean>(false);
  const [chainVerifiedMessage, setChainVerifiedMessage] = useState<string | null>(null);

  // Risk Engine State
  const [riskMetrics, setRiskMetrics] = useState<RiskMetrics | null>(null);
  const [simAmount, setSimAmount] = useState<number>(25000);
  const [simEmail, setSimEmail] = useState<string>('customer@example.com');
  const [simCard, setSimCard] = useState<string>('4000000000000001');
  const [isEvaluating, setIsEvaluating] = useState<boolean>(false);
  const [simResult, setSimResult] = useState<RiskSimulationResult | null>(null);

  // RBAC State
  const [roles, setRoles] = useState<RolePolicyItem[]>([]);
  const [totalUsers, setTotalUsers] = useState<number>(0);

  // General Loading & Notification
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [feedbackMessage, setFeedbackMessage] = useState<{ text: string; type: 'success' | 'info' | 'error' } | null>(null);

  const loadAllData = async () => {
    setIsLoading(true);
    try {
      const [logsRes, chainRes, metricsRes, rolesRes] = await Promise.allSettled([
        apiService.getAuditLogs(apiKey, {
          action: selectedAction !== 'ALL' ? selectedAction : undefined,
          actor_type: selectedActor !== 'ALL' ? selectedActor : undefined,
        }),
        apiService.verifyAuditChain(apiKey),
        apiService.getRiskMetrics(apiKey),
        apiService.getRolesAndUsers(apiKey),
      ]);

      if (logsRes.status === 'fulfilled') {
        setAuditLogs(logsRes.value.data);
      }
      if (chainRes.status === 'fulfilled') {
        setChainStatus(chainRes.value);
      }
      if (metricsRes.status === 'fulfilled') {
        setRiskMetrics(metricsRes.value);
      }
      if (rolesRes.status === 'fulfilled') {
        setRoles(rolesRes.value.roles);
        setTotalUsers(rolesRes.value.total_users);
      }
    } catch (err: any) {
      console.warn('Failed to load security view data:', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadAllData();
  }, [apiKey, selectedAction, selectedActor]);

  const handleVerifyChain = async () => {
    setIsVerifyingChain(true);
    setChainVerifiedMessage(null);
    try {
      const result = await apiService.verifyAuditChain(apiKey);
      setChainStatus(result);
      if (result.is_chain_healthy) {
        setChainVerifiedMessage(`Integrity verified! All ${result.total_records} audit entries are cryptographically linked with SHA-256 hash chaining.`);
      } else {
        setFeedbackMessage({
          type: 'error',
          text: `Chain integrity warning: ${result.corrupted_records.length} record(s) failed cryptographic verification.`,
        });
      }
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: 'Chain verification request failed.' });
    } finally {
      setIsVerifyingChain(false);
    }
  };

  const handleRunSimulation = async (customPayload?: { amount: number; email: string; card: string }) => {
    setIsEvaluating(true);
    setFeedbackMessage(null);
    try {
      const payload = customPayload
        ? {
            amount: customPayload.amount * 100,
            customer_email: customPayload.email,
            card_number: customPayload.card,
            currency: 'NGN',
          }
        : {
            amount: Math.round(Number(simAmount) * 100),
            customer_email: simEmail,
            card_number: simCard,
            currency: 'NGN',
          };

      const result = await apiService.evaluateRiskSimulator(apiKey, payload);
      setSimResult(result);
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: 'Risk evaluation failed. Check parameters.' });
    } finally {
      setIsEvaluating(false);
    }
  };

  const applyPreset = (preset: { name: string; amount: number; email: string; card: string }) => {
    setSimAmount(preset.amount);
    setSimEmail(preset.email);
    setSimCard(preset.card);
    handleRunSimulation(preset);
  };

  return (
    <div style={{ padding: '24px', maxWidth: '1400px', margin: '0 auto', color: '#f8fafc' }}>
      {/* Top Banner / Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '24px', flexWrap: 'wrap', gap: '16px' }}>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
            <div style={{ width: '42px', height: '42px', borderRadius: '12px', background: 'linear-gradient(135deg, #3b82f6, #6366f1)', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 4px 12px rgba(59, 130, 246, 0.3)' }}>
              <ShieldCheck size={24} color="#ffffff" />
            </div>
            <div>
              <h1 style={{ fontSize: '24px', fontWeight: 700, margin: 0, letterSpacing: '-0.02em' }}>Enterprise Security & Risk Rules</h1>
              <p style={{ margin: '4px 0 0 0', fontSize: '14px', color: '#94a3b8' }}>
                Cryptographic SHA-256 audit ledger, role-based access control (RBAC), and real-time payment fraud heuristics.
              </p>
            </div>
          </div>
        </div>

        <div style={{ display: 'flex', gap: '12px', alignItems: 'center' }}>
          <button
            onClick={handleVerifyChain}
            disabled={isVerifyingChain}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              padding: '10px 18px',
              borderRadius: '8px',
              background: 'linear-gradient(135deg, #10b981, #059669)',
              color: '#ffffff',
              border: 'none',
              fontWeight: 600,
              fontSize: '13px',
              cursor: isVerifyingChain ? 'not-allowed' : 'pointer',
              boxShadow: '0 4px 12px rgba(16, 185, 129, 0.25)',
              transition: 'all 0.2s ease',
            }}
          >
            <ShieldCheck size={16} />
            {isVerifyingChain ? 'Verifying Chain...' : 'Verify Audit Hash Chain'}
          </button>

          <button
            onClick={loadAllData}
            disabled={isLoading}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              padding: '10px 16px',
              borderRadius: '8px',
              background: '#1e293b',
              color: '#e2e8f0',
              border: '1px solid #334155',
              fontWeight: 500,
              fontSize: '13px',
              cursor: isLoading ? 'not-allowed' : 'pointer',
            }}
          >
            <RefreshCw size={15} className={isLoading ? 'animate-spin' : ''} />
            Refresh
          </button>
        </div>
      </div>

      {/* Notifications / Alerts */}
      {chainVerifiedMessage && (
        <div style={{ padding: '14px 18px', borderRadius: '10px', background: 'rgba(16, 185, 129, 0.15)', border: '1px solid rgba(16, 185, 129, 0.4)', marginBottom: '20px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <CheckCircle2 size={18} color="#10b981" />
            <span style={{ fontSize: '13px', color: '#6ee7b7', fontWeight: 500 }}>{chainVerifiedMessage}</span>
          </div>
          <button onClick={() => setChainVerifiedMessage(null)} style={{ background: 'transparent', border: 'none', color: '#94a3b8', cursor: 'pointer' }}>
            <X size={16} />
          </button>
        </div>
      )}

      {feedbackMessage && (
        <div style={{ padding: '14px 18px', borderRadius: '10px', background: feedbackMessage.type === 'error' ? 'rgba(239, 68, 68, 0.15)' : 'rgba(59, 130, 246, 0.15)', border: `1px solid ${feedbackMessage.type === 'error' ? 'rgba(239, 68, 68, 0.4)' : 'rgba(59, 130, 246, 0.4)'}`, marginBottom: '20px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <span style={{ fontSize: '13px', color: feedbackMessage.type === 'error' ? '#fca5a5' : '#93c5fd', fontWeight: 500 }}>{feedbackMessage.text}</span>
          <button onClick={() => setFeedbackMessage(null)} style={{ background: 'transparent', border: 'none', color: '#94a3b8', cursor: 'pointer' }}>
            <X size={16} />
          </button>
        </div>
      )}

      {/* Top 4 Hero Cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: '16px', marginBottom: '28px' }}>
        {/* Card 1: Chain Integrity */}
        <div style={{ background: '#0f172a', border: '1px solid #1e293b', borderRadius: '12px', padding: '20px', display: 'flex', flexDirection: 'column', position: 'relative', overflow: 'hidden' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <span style={{ fontSize: '12px', color: '#94a3b8', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Cryptographic Audit Ledger</span>
            <div style={{ padding: '4px 8px', borderRadius: '6px', background: chainStatus?.is_chain_healthy ? 'rgba(16, 185, 129, 0.2)' : 'rgba(239, 68, 68, 0.2)', color: chainStatus?.is_chain_healthy ? '#10b981' : '#ef4444', fontSize: '11px', fontWeight: 700 }}>
              {chainStatus?.is_chain_healthy ? 'HEALTHY' : 'PENDING'}
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: 700, margin: '12px 0 4px', color: '#f8fafc' }}>
            {chainStatus?.total_records ?? auditLogs.length} Records
          </div>
          <div style={{ fontSize: '12px', color: '#64748b', display: 'flex', alignItems: 'center', gap: '6px' }}>
            <Lock size={13} color="#10b981" />
            <span>SHA-256 Chained Hash Verification</span>
          </div>
        </div>

        {/* Card 2: Risk Telemetry */}
        <div style={{ background: '#0f172a', border: '1px solid #1e293b', borderRadius: '12px', padding: '20px', display: 'flex', flexDirection: 'column' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <span style={{ fontSize: '12px', color: '#94a3b8', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Payment Risk Mitigation</span>
            <div style={{ padding: '4px 8px', borderRadius: '6px', background: 'rgba(59, 130, 246, 0.15)', color: '#60a5fa', fontSize: '11px', fontWeight: 700 }}>
              REAL-TIME
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: 700, margin: '12px 0 4px', color: '#f8fafc' }}>
            {riskMetrics?.total_blocked ?? 0} Blocked
          </div>
          <div style={{ fontSize: '12px', color: '#64748b', display: 'flex', alignItems: 'center', gap: '6px' }}>
            <Activity size={13} color="#60a5fa" />
            <span>{riskMetrics?.active_rules_count ?? 4} Active Fraud & Velocity Heuristics</span>
          </div>
        </div>

        {/* Card 3: RBAC Roles */}
        <div style={{ background: '#0f172a', border: '1px solid #1e293b', borderRadius: '12px', padding: '20px', display: 'flex', flexDirection: 'column' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <span style={{ fontSize: '12px', color: '#94a3b8', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Institutional Access Control</span>
            <div style={{ padding: '4px 8px', borderRadius: '6px', background: 'rgba(139, 92, 246, 0.15)', color: '#c084fc', fontSize: '11px', fontWeight: 700 }}>
              4 ROLES
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: 700, margin: '12px 0 4px', color: '#f8fafc' }}>
            {totalUsers || 4} Active Users
          </div>
          <div style={{ fontSize: '12px', color: '#64748b', display: 'flex', alignItems: 'center', gap: '6px' }}>
            <Key size={13} color="#c084fc" />
            <span>Admin, Merchant, Auditor, Operations</span>
          </div>
        </div>

        {/* Card 4: Compliance Standard */}
        <div style={{ background: '#0f172a', border: '1px solid #1e293b', borderRadius: '12px', padding: '20px', display: 'flex', flexDirection: 'column' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <span style={{ fontSize: '12px', color: '#94a3b8', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Compliance Standard</span>
            <div style={{ padding: '4px 8px', borderRadius: '6px', background: 'rgba(16, 185, 129, 0.15)', color: '#34d399', fontSize: '11px', fontWeight: 700 }}>
              PCI-DSS LEVEL 1
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: 700, margin: '12px 0 4px', color: '#34d399' }}>
            Masked PAN
          </div>
          <div style={{ fontSize: '12px', color: '#64748b', display: 'flex', alignItems: 'center', gap: '6px' }}>
            <Cpu size={13} color="#34d399" />
            <span>Zero CVV storage & Secret Key Hashing</span>
          </div>
        </div>
      </div>

      {/* Navigation Tabs */}
      <div style={{ display: 'flex', borderBottom: '1px solid #1e293b', marginBottom: '24px', gap: '8px' }}>
        <button
          onClick={() => setActiveTab('audit')}
          style={{
            padding: '12px 20px',
            background: 'transparent',
            border: 'none',
            borderBottom: activeTab === 'audit' ? '2px solid #3b82f6' : '2px solid transparent',
            color: activeTab === 'audit' ? '#60a5fa' : '#94a3b8',
            fontWeight: 600,
            fontSize: '14px',
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            cursor: 'pointer',
          }}
        >
          <FileText size={16} />
          Tamper-Evident Audit Trails ({auditLogs.length})
        </button>

        <button
          onClick={() => setActiveTab('risk')}
          style={{
            padding: '12px 20px',
            background: 'transparent',
            border: 'none',
            borderBottom: activeTab === 'risk' ? '2px solid #3b82f6' : '2px solid transparent',
            color: activeTab === 'risk' ? '#60a5fa' : '#94a3b8',
            fontWeight: 600,
            fontSize: '14px',
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            cursor: 'pointer',
          }}
        >
          <ShieldAlert size={16} />
          Payment Risk & Velocity Simulator
        </button>

        <button
          onClick={() => setActiveTab('rbac')}
          style={{
            padding: '12px 20px',
            background: 'transparent',
            border: 'none',
            borderBottom: activeTab === 'rbac' ? '2px solid #3b82f6' : '2px solid transparent',
            color: activeTab === 'rbac' ? '#60a5fa' : '#94a3b8',
            fontWeight: 600,
            fontSize: '14px',
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            cursor: 'pointer',
          }}
        >
          <Users size={16} />
          Institutional RBAC Matrix ({roles.length} Roles)
        </button>
      </div>

      {/* TAB 1: AUDIT TRAILS EXPLORER */}
      {activeTab === 'audit' && (
        <div>
          {/* Filters Bar */}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap', gap: '12px', background: '#0f172a', padding: '16px', borderRadius: '10px', border: '1px solid #1e293b' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }}>
              <div>
                <label style={{ display: 'block', fontSize: '11px', color: '#64748b', fontWeight: 600, marginBottom: '4px', textTransform: 'uppercase' }}>Filter Action</label>
                <select
                  value={selectedAction}
                  onChange={(e) => setSelectedAction(e.target.value)}
                  style={{ background: '#1e293b', border: '1px solid #334155', borderRadius: '6px', color: '#f8fafc', padding: '6px 12px', fontSize: '13px', outline: 'none' }}
                >
                  <option value="ALL">All Actions</option>
                  <option value="PAYMENT_CAPTURED">PAYMENT_CAPTURED</option>
                  <option value="PAYMENT_DECLINED">PAYMENT_DECLINED</option>
                  <option value="PAYMENT_RISK_BLOCKED">PAYMENT_RISK_BLOCKED</option>
                  <option value="SETTLEMENT_BATCH_GENERATED">SETTLEMENT_BATCH_GENERATED</option>
                  <option value="SETTLEMENT_PAYOUT_EXECUTED">SETTLEMENT_PAYOUT_EXECUTED</option>
                  <option value="RECONCILIATION_RUN_COMPLETED">RECONCILIATION_RUN_COMPLETED</option>
                  <option value="RECONCILIATION_EXCEPTION_RESOLVED">RECONCILIATION_EXCEPTION_RESOLVED</option>
                </select>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: '11px', color: '#64748b', fontWeight: 600, marginBottom: '4px', textTransform: 'uppercase' }}>Actor Type</label>
                <select
                  value={selectedActor}
                  onChange={(e) => setSelectedActor(e.target.value)}
                  style={{ background: '#1e293b', border: '1px solid #334155', borderRadius: '6px', color: '#f8fafc', padding: '6px 12px', fontSize: '13px', outline: 'none' }}
                >
                  <option value="ALL">All Actors</option>
                  <option value="SYSTEM">SYSTEM</option>
                  <option value="API">API</option>
                  <option value="OPERATIONS">OPERATIONS</option>
                  <option value="MERCHANT">MERCHANT</option>
                </select>
              </div>
            </div>

            <div style={{ fontSize: '13px', color: '#94a3b8' }}>
              Showing <span style={{ color: '#f8fafc', fontWeight: 600 }}>{auditLogs.length}</span> immutable log records
            </div>
          </div>

          {/* Audit Logs Table */}
          <div style={{ background: '#0f172a', border: '1px solid #1e293b', borderRadius: '12px', overflow: 'hidden' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '13px' }}>
              <thead>
                <tr style={{ background: '#1e293b', color: '#94a3b8', borderBottom: '1px solid #334155' }}>
                  <th style={{ padding: '12px 16px', fontWeight: 600 }}>Timestamp</th>
                  <th style={{ padding: '12px 16px', fontWeight: 600 }}>Action</th>
                  <th style={{ padding: '12px 16px', fontWeight: 600 }}>Entity</th>
                  <th style={{ padding: '12px 16px', fontWeight: 600 }}>Actor</th>
                  <th style={{ padding: '12px 16px', fontWeight: 600 }}>Integrity Status</th>
                  <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {auditLogs.length === 0 ? (
                  <tr>
                    <td colSpan={6} style={{ padding: '40px 16px', textAlign: 'center', color: '#64748b' }}>
                      No audit log entries found matching selected filters.
                    </td>
                  </tr>
                ) : (
                  auditLogs.map((log) => {
                    const isRiskBlock = log.action.includes('RISK_BLOCKED');
                    const isSuccess = log.action.includes('CAPTURED') || log.action.includes('EXECUTED');
                    const isSettlement = log.action.includes('SETTLEMENT');

                    return (
                      <tr key={log.id} style={{ borderBottom: '1px solid #1e293b', transition: 'background 0.15s ease' }}>
                        <td style={{ padding: '14px 16px', color: '#cbd5e1', whiteSpace: 'nowrap' }}>
                          {new Date(log.created_at).toLocaleString('en-US', {
                            month: 'short',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit',
                          })}
                        </td>
                        <td style={{ padding: '14px 16px' }}>
                          <span
                            style={{
                              display: 'inline-block',
                              padding: '3px 8px',
                              borderRadius: '6px',
                              fontSize: '11px',
                              fontWeight: 600,
                              background: isRiskBlock
                                ? 'rgba(239, 68, 68, 0.15)'
                                : isSuccess
                                ? 'rgba(16, 185, 129, 0.15)'
                                : isSettlement
                                ? 'rgba(139, 92, 246, 0.15)'
                                : 'rgba(59, 130, 246, 0.15)',
                              color: isRiskBlock
                                ? '#f87171'
                                : isSuccess
                                ? '#34d399'
                                : isSettlement
                                ? '#c084fc'
                                : '#60a5fa',
                            }}
                          >
                            {log.action}
                          </span>
                        </td>
                        <td style={{ padding: '14px 16px', color: '#94a3b8' }}>
                          <span style={{ color: '#e2e8f0', fontWeight: 500 }}>{log.entity_type}</span>
                          <span style={{ fontSize: '11px', color: '#64748b', display: 'block', fontFamily: 'monospace' }}>
                            {log.entity_id ? `${String(log.entity_id).substring(0, 16)}...` : '-'}
                          </span>
                        </td>
                        <td style={{ padding: '14px 16px' }}>
                          <span style={{ padding: '2px 6px', borderRadius: '4px', background: '#334155', color: '#cbd5e1', fontSize: '11px', fontWeight: 600 }}>
                            {log.actor_type}
                          </span>
                          <span style={{ display: 'block', fontSize: '11px', color: '#64748b', marginTop: '2px' }}>
                            {log.user?.email || log.ip_address || '127.0.0.1'}
                          </span>
                        </td>
                        <td style={{ padding: '14px 16px' }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                            <div style={{ width: '8px', height: '8px', borderRadius: '50%', background: '#10b981' }} />
                            <span style={{ fontSize: '12px', color: '#10b981', fontWeight: 500 }}>SHA-256 Verified</span>
                          </div>
                          <span style={{ fontSize: '10px', color: '#64748b', fontFamily: 'monospace' }}>
                            {log.new_values?._integrity?.hash ? `${log.new_values._integrity.hash.substring(0, 12)}...` : 'genesis'}
                          </span>
                        </td>
                        <td style={{ padding: '14px 16px', textAlign: 'right' }}>
                          <button
                            onClick={() => setSelectedLog(log)}
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '6px',
                              padding: '6px 12px',
                              borderRadius: '6px',
                              background: '#1e293b',
                              color: '#93c5fd',
                              border: '1px solid #334155',
                              fontSize: '12px',
                              fontWeight: 500,
                              cursor: 'pointer',
                            }}
                          >
                            <Eye size={13} />
                            Inspect State
                          </button>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* TAB 2: RISK ENGINE & SIMULATOR */}
      {activeTab === 'risk' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
          {/* AI Machine Learning Fraud Engine Telemetry Banner */}
          <div
            style={{
              background: 'linear-gradient(135deg, rgba(30, 41, 59, 0.9) 0%, rgba(15, 23, 42, 0.95) 100%)',
              border: '1px solid #334155',
              borderRadius: '12px',
              padding: '20px 24px',
              display: 'flex',
              flexWrap: 'wrap',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: '20px',
              boxShadow: '0 4px 20px rgba(0, 0, 0, 0.25)',
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: '16px' }}>
              <div
                style={{
                  width: '48px',
                  height: '48px',
                  borderRadius: '12px',
                  background: 'linear-gradient(135deg, #6366f1, #8b5cf6)',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  boxShadow: '0 4px 14px rgba(99, 102, 241, 0.35)',
                }}
              >
                <BrainCircuit size={26} color="#ffffff" />
              </div>
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                  <h3 style={{ margin: 0, fontSize: '17px', fontWeight: 700, color: '#f8fafc' }}>
                    Machine Learning Fraud Detection Microservice
                  </h3>
                  <span
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: '6px',
                      padding: '3px 10px',
                      borderRadius: '9999px',
                      fontSize: '11px',
                      fontWeight: 700,
                      letterSpacing: '0.04em',
                      background:
                        riskMetrics?.ml_fraud_engine?.status === 'ONLINE'
                          ? 'rgba(16, 185, 129, 0.15)'
                          : 'rgba(245, 158, 11, 0.15)',
                      color:
                        riskMetrics?.ml_fraud_engine?.status === 'ONLINE' ? '#34d399' : '#fbbf24',
                      border: `1px solid ${
                        riskMetrics?.ml_fraud_engine?.status === 'ONLINE'
                          ? 'rgba(16, 185, 129, 0.3)'
                          : 'rgba(245, 158, 11, 0.3)'
                      }`,
                    }}
                  >
                    <span
                      style={{
                        width: '7px',
                        height: '7px',
                        borderRadius: '50%',
                        background:
                          riskMetrics?.ml_fraud_engine?.status === 'ONLINE' ? '#10b981' : '#f59e0b',
                        boxShadow:
                          riskMetrics?.ml_fraud_engine?.status === 'ONLINE'
                            ? '0 0 8px #10b981'
                            : 'none',
                      }}
                    />
                    {riskMetrics?.ml_fraud_engine?.status ?? 'ONLINE'}
                  </span>
                </div>
                <p style={{ margin: '4px 0 0 0', fontSize: '13px', color: '#94a3b8' }}>
                  {riskMetrics?.ml_fraud_engine?.model ?? 'Isolation Forest Anomaly Detector v1.0'} &bull;{' '}
                  {riskMetrics?.ml_fraud_engine?.algorithm ?? 'Unsupervised IsolationForest (scikit-learn)'} &bull;{' '}
                  {riskMetrics?.ml_fraud_engine?.features_count ?? 7} Engineered Behavioral Features
                </p>
              </div>
            </div>

            <div style={{ display: 'flex', gap: '24px', alignItems: 'center' }}>
              <div style={{ textAlign: 'right' }}>
                <span style={{ fontSize: '11px', color: '#64748b', textTransform: 'uppercase', fontWeight: 600 }}>
                  Latency SLA
                </span>
                <div style={{ fontSize: '14px', fontWeight: 700, color: '#38bdf8' }}>
                  &lt; 800ms Cutoff
                </div>
              </div>
              <div style={{ height: '32px', width: '1px', background: '#334155' }} />
              <div style={{ textAlign: 'right' }}>
                <span style={{ fontSize: '11px', color: '#64748b', textTransform: 'uppercase', fontWeight: 600 }}>
                  Evaluations
                </span>
                <div style={{ fontSize: '14px', fontWeight: 700, color: '#f8fafc' }}>
                  {riskMetrics?.total_evaluated ?? 0}
                </div>
              </div>
              <div style={{ height: '32px', width: '1px', background: '#334155' }} />
              <div style={{ textAlign: 'right' }}>
                <span style={{ fontSize: '11px', color: '#64748b', textTransform: 'uppercase', fontWeight: 600 }}>
                  Block Rate
                </span>
                <div style={{ fontSize: '14px', fontWeight: 700, color: '#f87171' }}>
                  {riskMetrics?.block_rate_percentage ?? 0}%
                </div>
              </div>
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '24px' }}>
            {/* Left Column: Active Risk Heuristics */}
            <div style={{ background: '#0f172a', border: '1px solid #1e293b', borderRadius: '12px', padding: '24px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '20px' }}>
                <ShieldAlert size={20} color="#f59e0b" />
                <h2 style={{ fontSize: '18px', fontWeight: 600, margin: 0 }}>Active Risk & Fraud Heuristics</h2>
              </div>
              <p style={{ fontSize: '13px', color: '#94a3b8', margin: '0 0 20px 0', lineHeight: 1.5 }}>
                TransactIQ automatically screens each incoming transaction before gateway dispatch through the following deterministic security checks and unsupervised machine learning algorithms.
              </p>

              <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                {/* Rule 1 */}
                <div style={{ padding: '16px', borderRadius: '10px', background: '#1e293b', border: '1px solid #334155' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                    <span style={{ fontSize: '14px', fontWeight: 600, color: '#f8fafc' }}>1. Velocity Spike Guard</span>
                    <span style={{ padding: '2px 8px', borderRadius: '4px', background: 'rgba(239, 68, 68, 0.2)', color: '#f87171', fontSize: '11px', fontWeight: 600 }}>BLOCK (+60)</span>
                  </div>
                  <p style={{ fontSize: '12px', color: '#94a3b8', margin: '0 0 8px 0' }}>
                    Restricts cards and customer emails from exceeding 5 payment attempts within a rolling 60-second window.
                  </p>
                  <div style={{ fontSize: '11px', color: '#64748b', display: 'flex', gap: '12px' }}>
                    <span>Threshold: <strong>&gt; 5 attempts / min</strong></span>
                    <span>Scope: <strong>Customer Email / Card</strong></span>
                  </div>
                </div>

                {/* Rule 2 */}
                <div style={{ padding: '16px', borderRadius: '10px', background: '#1e293b', border: '1px solid #334155' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                    <span style={{ fontSize: '14px', fontWeight: 600, color: '#f8fafc' }}>2. Global Card Blacklist</span>
                    <span style={{ padding: '2px 8px', borderRadius: '4px', background: 'rgba(239, 68, 68, 0.2)', color: '#f87171', fontSize: '11px', fontWeight: 600 }}>BLOCK (100)</span>
                  </div>
                  <p style={{ fontSize: '12px', color: '#94a3b8', margin: '0 0 8px 0' }}>
                    Immediate rejection of reported stolen cards, fraud rings, and compromised card fingerprints ending in 9999 or 8888.
                  </p>
                  <div style={{ fontSize: '11px', color: '#64748b', display: 'flex', gap: '12px' }}>
                    <span>Matching: <strong>Exact Fingerprint / Suffix</strong></span>
                    <span>Action: <strong>Instant Abort</strong></span>
                  </div>
                </div>

                {/* Rule 3 */}
                <div style={{ padding: '16px', borderRadius: '10px', background: '#1e293b', border: '1px solid #334155' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                    <span style={{ fontSize: '14px', fontWeight: 600, color: '#f8fafc' }}>3. Consecutive Decline Guard</span>
                    <span style={{ padding: '2px 8px', borderRadius: '4px', background: 'rgba(245, 158, 11, 0.2)', color: '#fbbf24', fontSize: '11px', fontWeight: 600 }}>REVIEW (+35)</span>
                  </div>
                  <p style={{ fontSize: '12px', color: '#94a3b8', margin: '0 0 8px 0' }}>
                    Detects brute force card guessing attempts when a customer has 3 or more consecutive card declines without success.
                  </p>
                  <div style={{ fontSize: '11px', color: '#64748b', display: 'flex', gap: '12px' }}>
                    <span>Threshold: <strong>&gt;= 3 Declines</strong></span>
                    <span>Action: <strong>Route to Review</strong></span>
                  </div>
                </div>

                {/* Rule 4 */}
                <div style={{ padding: '16px', borderRadius: '10px', background: '#1e293b', border: '1px solid #334155' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                    <span style={{ fontSize: '14px', fontWeight: 600, color: '#f8fafc' }}>4. High-Ticket Volume Anomaly</span>
                    <span style={{ padding: '2px 8px', borderRadius: '4px', background: 'rgba(245, 158, 11, 0.2)', color: '#fbbf24', fontSize: '11px', fontWeight: 600 }}>REVIEW (+30)</span>
                  </div>
                  <p style={{ fontSize: '12px', color: '#94a3b8', margin: '0 0 8px 0' }}>
                    Flags single transactions exceeding ₦2,000,000 for secondary operational review and compliance verification.
                  </p>
                  <div style={{ fontSize: '11px', color: '#64748b', display: 'flex', gap: '12px' }}>
                    <span>Threshold: <strong>&gt; ₦2,000,000.00</strong></span>
                    <span>Action: <strong>Compliance Alert</strong></span>
                  </div>
                </div>

                {/* Rule 5 */}
                <div style={{ padding: '16px', borderRadius: '10px', background: '#1e293b', border: '1px solid #334155' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                    <span style={{ fontSize: '14px', fontWeight: 600, color: '#f8fafc' }}>5. Disposable Domain Filter</span>
                    <span style={{ padding: '2px 8px', borderRadius: '4px', background: 'rgba(192, 132, 252, 0.2)', color: '#c084fc', fontSize: '11px', fontWeight: 600 }}>FLAG (+20)</span>
                  </div>
                  <p style={{ fontSize: '12px', color: '#94a3b8', margin: '0 0 8px 0' }}>
                    Identifies temporary disposable mailboxes (tempmail, 10minutemail, throwaway) associated with burner identities.
                  </p>
                  <div style={{ fontSize: '11px', color: '#64748b', display: 'flex', gap: '12px' }}>
                    <span>Domains: <strong>50+ Burner Providers</strong></span>
                    <span>Action: <strong>Risk Score Elevation</strong></span>
                  </div>
                </div>

                {/* Rule 6 - ML Anomaly */}
                <div style={{ padding: '16px', borderRadius: '10px', background: 'linear-gradient(135deg, rgba(30, 41, 59, 1), rgba(49, 46, 129, 0.25))', border: '1px solid #4f46e5' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                      <BrainCircuit size={15} color="#818cf8" />
                      <span style={{ fontSize: '14px', fontWeight: 700, color: '#c7d2fe' }}>6. Machine Learning Anomaly Score</span>
                    </div>
                    <span style={{ padding: '2px 8px', borderRadius: '4px', background: 'rgba(99, 102, 241, 0.25)', color: '#a5b4fc', fontSize: '11px', fontWeight: 700 }}>AI / ML</span>
                  </div>
                  <p style={{ fontSize: '12px', color: '#94a3b8', margin: '0 0 8px 0' }}>
                    Unsupervised Isolation Forest evaluates 7 high-dimensional vectors. Anomaly scores &ge; 0.75 trigger REVIEW (+30); &ge; 0.88 trigger BLOCK (+50).
                  </p>
                  <div style={{ fontSize: '11px', color: '#64748b', display: 'flex', gap: '12px' }}>
                    <span>Engine: <strong>FastAPI Python 3.10</strong></span>
                    <span>Failover: <strong>Autonomous Heuristics</strong></span>
                  </div>
                </div>
              </div>
            </div>

            {/* Right Column: Interactive Simulator */}
            <div style={{ background: '#0f172a', border: '1px solid #1e293b', borderRadius: '12px', padding: '24px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '16px' }}>
                <Cpu size={20} color="#3b82f6" />
                <h2 style={{ fontSize: '18px', fontWeight: 600, margin: 0 }}>Interactive Risk Simulator Sandbox</h2>
              </div>
              <p style={{ fontSize: '13px', color: '#94a3b8', margin: '0 0 20px 0', lineHeight: 1.5 }}>
                Test any transaction payload in real-time to observe heuristic score calculations and AI Isolation Forest predictions.
              </p>

              {/* Quick Presets */}
              <div style={{ marginBottom: '20px' }}>
                <label style={{ display: 'block', fontSize: '11px', color: '#64748b', fontWeight: 600, marginBottom: '8px', textTransform: 'uppercase' }}>Quick Preset Scenarios</label>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' }}>
                  <button
                    type="button"
                    onClick={() => applyPreset({ name: 'Standard Payment', amount: 15000, email: 'john@example.com', card: '4000000000000001' })}
                    style={{ padding: '8px 12px', borderRadius: '6px', background: '#1e293b', border: '1px solid #334155', color: '#e2e8f0', fontSize: '12px', textAlign: 'left', cursor: 'pointer' }}
                  >
                    <span style={{ color: '#10b981', fontWeight: 600 }}>Standard Payment</span>
                    <span style={{ display: 'block', fontSize: '11px', color: '#64748b' }}>₦15,000 (Expected: ALLOW)</span>
                  </button>

                  <button
                    type="button"
                    onClick={() => applyPreset({ name: 'Stolen Card', amount: 80000, email: 'badactor@example.com', card: '4000000000009999' })}
                    style={{ padding: '8px 12px', borderRadius: '6px', background: '#1e293b', border: '1px solid #334155', color: '#e2e8f0', fontSize: '12px', textAlign: 'left', cursor: 'pointer' }}
                  >
                    <span style={{ color: '#ef4444', fontWeight: 600 }}>Stolen Card (9999)</span>
                    <span style={{ display: 'block', fontSize: '11px', color: '#64748b' }}>Blacklist (Expected: BLOCK)</span>
                  </button>

                  <button
                    type="button"
                    onClick={() => applyPreset({ name: 'High-Ticket Volume', amount: 3500000, email: 'corp@enterprise.com', card: '5100000000000001' })}
                    style={{ padding: '8px 12px', borderRadius: '6px', background: '#1e293b', border: '1px solid #334155', color: '#e2e8f0', fontSize: '12px', textAlign: 'left', cursor: 'pointer' }}
                  >
                    <span style={{ color: '#f59e0b', fontWeight: 600 }}>High Ticket (₦3.5M)</span>
                    <span style={{ display: 'block', fontSize: '11px', color: '#64748b' }}>Anomaly (Expected: REVIEW)</span>
                  </button>

                  <button
                    type="button"
                    onClick={() => applyPreset({ name: 'Suspicious Email', amount: 45000, email: 'fraudtest@throwaway.com', card: '4000000000000001' })}
                    style={{ padding: '8px 12px', borderRadius: '6px', background: '#1e293b', border: '1px solid #334155', color: '#e2e8f0', fontSize: '12px', textAlign: 'left', cursor: 'pointer' }}
                  >
                    <span style={{ color: '#c084fc', fontWeight: 600 }}>Disposable Email</span>
                    <span style={{ display: 'block', fontSize: '11px', color: '#64748b' }}>Pattern Flag (+20 score)</span>
                  </button>

                  <button
                    type="button"
                    onClick={() => applyPreset({ name: 'AI Anomaly Spike', amount: 850000, email: 'rapid.actor@tempinbox.org', card: '4000000000000002' })}
                    style={{ padding: '8px 12px', borderRadius: '6px', background: 'rgba(99, 102, 241, 0.1)', border: '1px solid #4f46e5', color: '#e2e8f0', fontSize: '12px', textAlign: 'left', cursor: 'pointer', gridColumn: 'span 2' }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                      <BrainCircuit size={13} color="#818cf8" />
                      <span style={{ color: '#a5b4fc', fontWeight: 600 }}>AI ML Anomaly Spike (Night + High Amount)</span>
                    </div>
                    <span style={{ display: 'block', fontSize: '11px', color: '#64748b', marginTop: '2px' }}>Multi-factor behavioral spike evaluated via Isolation Forest</span>
                  </button>
                </div>
              </div>

              {/* Input Form */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: '14px', marginBottom: '20px' }}>
                <div>
                  <label style={{ display: 'block', fontSize: '12px', color: '#94a3b8', marginBottom: '6px' }}>Amount (NGN)</label>
                  <input
                    type="number"
                    value={simAmount}
                    onChange={(e) => setSimAmount(Number(e.target.value))}
                    style={{ width: '100%', boxSizing: 'border-box', background: '#1e293b', border: '1px solid #334155', borderRadius: '8px', color: '#f8fafc', padding: '10px 14px', fontSize: '14px' }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: '12px', color: '#94a3b8', marginBottom: '6px' }}>Customer Email</label>
                  <input
                    type="email"
                    value={simEmail}
                    onChange={(e) => setSimEmail(e.target.value)}
                    style={{ width: '100%', boxSizing: 'border-box', background: '#1e293b', border: '1px solid #334155', borderRadius: '8px', color: '#f8fafc', padding: '10px 14px', fontSize: '14px' }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: '12px', color: '#94a3b8', marginBottom: '6px' }}>Card Number</label>
                  <input
                    type="text"
                    value={simCard}
                    onChange={(e) => setSimCard(e.target.value)}
                    placeholder="e.g. 4000000000009999"
                    style={{ width: '100%', boxSizing: 'border-box', background: '#1e293b', border: '1px solid #334155', borderRadius: '8px', color: '#f8fafc', padding: '10px 14px', fontSize: '14px' }}
                  />
                </div>

                <button
                  type="button"
                  onClick={() => handleRunSimulation()}
                  disabled={isEvaluating}
                  style={{
                    padding: '12px',
                    borderRadius: '8px',
                    background: 'linear-gradient(135deg, #3b82f6, #2563eb)',
                    color: '#ffffff',
                    border: 'none',
                    fontWeight: 600,
                    fontSize: '14px',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: '8px',
                    cursor: isEvaluating ? 'not-allowed' : 'pointer',
                    boxShadow: '0 4px 12px rgba(37, 99, 235, 0.3)',
                  }}
                >
                  <Play size={16} />
                  {isEvaluating ? 'Evaluating Heuristics & AI Model...' : 'Execute Risk Evaluation'}
                </button>
              </div>

              {/* Simulation Result Output */}
              {simResult && (
                <div
                  style={{
                    padding: '18px',
                    borderRadius: '10px',
                    background:
                      simResult.decision === 'BLOCK'
                        ? 'rgba(239, 68, 68, 0.1)'
                        : simResult.decision === 'REVIEW'
                        ? 'rgba(245, 158, 11, 0.1)'
                        : 'rgba(16, 185, 129, 0.1)',
                    border: `1px solid ${
                      simResult.decision === 'BLOCK'
                        ? 'rgba(239, 68, 68, 0.3)'
                        : simResult.decision === 'REVIEW'
                        ? 'rgba(245, 158, 11, 0.3)'
                        : 'rgba(16, 185, 129, 0.3)'
                    }`,
                  }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' }}>
                    <div>
                      <span style={{ fontSize: '11px', color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.05em' }}>Risk Decision</span>
                      <div style={{ fontSize: '20px', fontWeight: 800, color: simResult.decision === 'BLOCK' ? '#ef4444' : simResult.decision === 'REVIEW' ? '#f59e0b' : '#10b981' }}>
                        {simResult.decision}
                      </div>
                    </div>
                    <div style={{ textAlign: 'right' }}>
                      <span style={{ fontSize: '11px', color: '#94a3b8', textTransform: 'uppercase' }}>Fraud Score</span>
                      <div style={{ fontSize: '20px', fontWeight: 800, color: '#f8fafc' }}>
                        {simResult.score} / 100
                      </div>
                    </div>
                  </div>

                  {/* AI Isolation Forest Radar & Gauge Section */}
                  <div
                    style={{
                      margin: '14px 0',
                      padding: '14px',
                      borderRadius: '8px',
                      background: 'rgba(15, 23, 42, 0.75)',
                      border: '1px solid #334155',
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <BrainCircuit size={16} color="#818cf8" />
                        <span style={{ fontSize: '12px', fontWeight: 600, color: '#e2e8f0' }}>
                          AI Isolation Forest Radar
                        </span>
                      </div>
                      {simResult.ml_risk_level && (
                        <span
                          style={{
                            fontSize: '11px',
                            fontWeight: 700,
                            padding: '2px 8px',
                            borderRadius: '4px',
                            background:
                              simResult.ml_risk_level === 'CRITICAL'
                                ? 'rgba(239, 68, 68, 0.2)'
                                : simResult.ml_risk_level === 'HIGH'
                                ? 'rgba(245, 158, 11, 0.2)'
                                : simResult.ml_risk_level === 'MEDIUM'
                                ? 'rgba(59, 130, 246, 0.2)'
                                : 'rgba(16, 185, 129, 0.2)',
                            color:
                              simResult.ml_risk_level === 'CRITICAL'
                                ? '#f87171'
                                : simResult.ml_risk_level === 'HIGH'
                                ? '#fbbf24'
                                : simResult.ml_risk_level === 'MEDIUM'
                                ? '#60a5fa'
                                : '#34d399',
                          }}
                        >
                          ML RISK: {simResult.ml_risk_level}
                        </span>
                      )}
                    </div>

                    {/* Anomaly Score Bar Gauge */}
                    <div style={{ marginBottom: '10px' }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '11px', color: '#94a3b8', marginBottom: '4px' }}>
                        <span>Normalized Anomaly Score</span>
                        <span style={{ fontWeight: 600, color: '#f8fafc' }}>
                          {simResult.ml_anomaly_score !== null && simResult.ml_anomaly_score !== undefined
                            ? `${(simResult.ml_anomaly_score * 100).toFixed(1)}%`
                            : 'N/A (Heuristic Standby)'}
                        </span>
                      </div>
                      <div style={{ width: '100%', height: '8px', background: '#1e293b', borderRadius: '4px', overflow: 'hidden' }}>
                        <div
                          style={{
                            width: `${Math.min(100, Math.max(0, (simResult.ml_anomaly_score ?? 0) * 100))}%`,
                            height: '100%',
                            background:
                              (simResult.ml_anomaly_score ?? 0) >= 0.88
                                ? 'linear-gradient(90deg, #f59e0b, #ef4444)'
                                : (simResult.ml_anomaly_score ?? 0) >= 0.75
                                ? 'linear-gradient(90deg, #3b82f6, #f59e0b)'
                                : 'linear-gradient(90deg, #10b981, #3b82f6)',
                            transition: 'width 0.4s ease-out',
                          }}
                        />
                      </div>
                    </div>

                    {/* ML Anomaly Factor Contribution Tags */}
                    {simResult.ml_anomaly_factors && simResult.ml_anomaly_factors.length > 0 && (
                      <div>
                        <span style={{ fontSize: '10px', color: '#64748b', display: 'block', marginBottom: '5px', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                          Contributing Behavioral Factors:
                        </span>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px' }}>
                          {simResult.ml_anomaly_factors.map((factor, idx) => (
                            <span
                              key={idx}
                              style={{
                                padding: '2px 7px',
                                borderRadius: '4px',
                                background: '#1e293b',
                                border: '1px solid #4338ca',
                                color: '#c7d2fe',
                                fontSize: '10px',
                                fontWeight: 600,
                              }}
                            >
                              {factor}
                            </span>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>

                  <div style={{ fontSize: '13px', color: '#e2e8f0', marginBottom: '12px', lineHeight: 1.4 }}>
                    <strong>Evaluation Reason:</strong> {simResult.reason}
                  </div>

                  {simResult.flags.length > 0 && (
                    <div>
                      <span style={{ fontSize: '11px', color: '#94a3b8', display: 'block', marginBottom: '6px' }}>Triggered Security Flags:</span>
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px' }}>
                        {simResult.flags.map((flag, idx) => (
                          <span key={idx} style={{ padding: '3px 8px', borderRadius: '4px', background: '#1e293b', border: '1px solid #475569', fontSize: '11px', fontWeight: 600, color: '#fca5a5' }}>
                            {flag}
                          </span>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* TAB 3: INSTITUTIONAL RBAC MATRIX */}
      {activeTab === 'rbac' && (
        <div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: '20px', marginBottom: '28px' }}>
            {roles.map((role) => {
              const isCrit = role.risk_level === 'CRITICAL';
              const isElev = role.risk_level === 'ELEVATED';
              const isStd = role.risk_level === 'STANDARD';

              return (
                <div key={role.id} style={{ background: '#0f172a', border: '1px solid #1e293b', borderRadius: '12px', padding: '20px', display: 'flex', flexDirection: 'column' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '12px' }}>
                    <div>
                      <h3 style={{ fontSize: '16px', fontWeight: 700, margin: 0, color: '#f8fafc' }}>{role.name}</h3>
                      <span style={{ fontSize: '12px', color: '#64748b', fontFamily: 'monospace' }}>role:{role.slug}</span>
                    </div>
                    <span
                      style={{
                        padding: '4px 8px',
                        borderRadius: '6px',
                        fontSize: '11px',
                        fontWeight: 700,
                        background: isCrit
                          ? 'rgba(239, 68, 68, 0.2)'
                          : isElev
                          ? 'rgba(245, 158, 11, 0.2)'
                          : isStd
                          ? 'rgba(59, 130, 246, 0.2)'
                          : 'rgba(16, 185, 129, 0.2)',
                        color: isCrit ? '#f87171' : isElev ? '#fbbf24' : isStd ? '#60a5fa' : '#34d399',
                      }}
                    >
                      {role.risk_level}
                    </span>
                  </div>

                  <p style={{ fontSize: '13px', color: '#94a3b8', margin: '0 0 16px 0', lineHeight: 1.4, minHeight: '38px' }}>
                    {role.description}
                  </p>

                  <div style={{ marginBottom: '16px' }}>
                    <span style={{ fontSize: '11px', color: '#64748b', fontWeight: 600, textTransform: 'uppercase', display: 'block', marginBottom: '8px' }}>Authorized Scopes</span>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px' }}>
                      {role.permissions.map((perm, pIdx) => (
                        <span key={pIdx} style={{ padding: '2px 8px', borderRadius: '4px', background: '#1e293b', border: '1px solid #334155', color: '#93c5fd', fontSize: '11px', fontFamily: 'monospace' }}>
                          {perm}
                        </span>
                      ))}
                    </div>
                  </div>

                  <div style={{ marginTop: 'auto', paddingTop: '16px', borderTop: '1px solid #1e293b' }}>
                    <span style={{ fontSize: '11px', color: '#64748b', fontWeight: 600, textTransform: 'uppercase', display: 'block', marginBottom: '8px' }}>
                      Assigned Platform Users ({role.users?.length ?? role.users_count})
                    </span>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '6px' }}>
                      {role.users && role.users.length > 0 ? (
                        role.users.map((u) => (
                          <div key={u.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: '12px', padding: '6px 10px', background: '#1e293b', borderRadius: '6px' }}>
                            <span style={{ color: '#e2e8f0', fontWeight: 500 }}>{u.name}</span>
                            <span style={{ color: '#64748b', fontSize: '11px' }}>{u.email}</span>
                          </div>
                        ))
                      ) : (
                        <span style={{ fontSize: '12px', color: '#475569' }}>No users assigned</span>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* INSPECT STATE / AUDIT DIFF MODAL */}
      {selectedLog && (
        <div style={{ position: 'fixed', top: 0, left: 0, right: 0, bottom: 0, background: 'rgba(0, 0, 0, 0.75)', backdropFilter: 'blur(4px)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: '20px' }}>
          <div style={{ background: '#0f172a', border: '1px solid #334155', borderRadius: '14px', width: '100%', maxWidth: '800px', maxHeight: '90vh', overflow: 'hidden', display: 'flex', flexDirection: 'column', boxShadow: '0 20px 40px rgba(0, 0, 0, 0.5)' }}>
            {/* Modal Header */}
            <div style={{ padding: '18px 24px', borderBottom: '1px solid #1e293b', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <Lock size={18} color="#10b981" />
                <h3 style={{ fontSize: '16px', fontWeight: 700, margin: 0 }}>Inspect Tamper-Evident Audit Record</h3>
              </div>
              <button onClick={() => setSelectedLog(null)} style={{ background: 'transparent', border: 'none', color: '#94a3b8', cursor: 'pointer' }}>
                <X size={20} />
              </button>
            </div>

            {/* Modal Content */}
            <div style={{ padding: '24px', overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '16px' }}>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', background: '#1e293b', padding: '16px', borderRadius: '8px' }}>
                <div>
                  <span style={{ fontSize: '11px', color: '#64748b', textTransform: 'uppercase' }}>Log UUID</span>
                  <div style={{ fontSize: '12px', color: '#e2e8f0', fontFamily: 'monospace' }}>{selectedLog.id}</div>
                </div>
                <div>
                  <span style={{ fontSize: '11px', color: '#64748b', textTransform: 'uppercase' }}>Action</span>
                  <div style={{ fontSize: '13px', fontWeight: 600, color: '#60a5fa' }}>{selectedLog.action}</div>
                </div>
                <div>
                  <span style={{ fontSize: '11px', color: '#64748b', textTransform: 'uppercase' }}>Entity Type & ID</span>
                  <div style={{ fontSize: '12px', color: '#e2e8f0' }}>{selectedLog.entity_type} ({selectedLog.entity_id})</div>
                </div>
                <div>
                  <span style={{ fontSize: '11px', color: '#64748b', textTransform: 'uppercase' }}>Timestamp</span>
                  <div style={{ fontSize: '12px', color: '#e2e8f0' }}>{new Date(selectedLog.created_at).toISOString()}</div>
                </div>
              </div>

              {/* Cryptographic Signature Info */}
              <div style={{ background: 'rgba(16, 185, 129, 0.08)', border: '1px solid rgba(16, 185, 129, 0.25)', padding: '14px', borderRadius: '8px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px' }}>
                  <ShieldCheck size={16} color="#10b981" />
                  <span style={{ fontSize: '12px', fontWeight: 700, color: '#10b981' }}>SHA-256 Cryptographic Chain Signature</span>
                </div>
                <div style={{ fontSize: '11px', color: '#94a3b8', marginBottom: '4px' }}>
                  Record Signature Hash:
                  <div style={{ color: '#6ee7b7', fontFamily: 'monospace', wordBreak: 'break-all', marginTop: '2px' }}>
                    {selectedLog.new_values?._integrity?.hash || 'N/A'}
                  </div>
                </div>
                <div style={{ fontSize: '11px', color: '#94a3b8' }}>
                  Previous Block Pointer:
                  <div style={{ color: '#cbd5e1', fontFamily: 'monospace', wordBreak: 'break-all', marginTop: '2px' }}>
                    {selectedLog.new_values?._integrity?.prev_hash || '0000000000000000000000000000000000000000000000000000000000000000'}
                  </div>
                </div>
              </div>

              {/* PCI-DSS Sanitized State */}
              <div>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                  <span style={{ fontSize: '12px', color: '#94a3b8', fontWeight: 600 }}>Mutated State Payload (PCI-DSS Sanitized)</span>
                  <span style={{ fontSize: '11px', color: '#34d399', fontWeight: 500 }}>PAN &amp; Secrets Redacted</span>
                </div>
                <pre style={{ background: '#020617', border: '1px solid #1e293b', borderRadius: '8px', padding: '14px', color: '#38bdf8', fontSize: '12px', fontFamily: 'monospace', overflowX: 'auto', margin: 0, maxHeight: '220px' }}>
                  {JSON.stringify(selectedLog.new_values, null, 2)}
                </pre>
              </div>
            </div>

            {/* Modal Footer */}
            <div style={{ padding: '14px 24px', borderTop: '1px solid #1e293b', display: 'flex', justifyContent: 'flex-end', background: '#0f172a' }}>
              <button
                onClick={() => setSelectedLog(null)}
                style={{ padding: '8px 18px', borderRadius: '6px', background: '#334155', color: '#f8fafc', border: 'none', fontSize: '13px', fontWeight: 500, cursor: 'pointer' }}
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
