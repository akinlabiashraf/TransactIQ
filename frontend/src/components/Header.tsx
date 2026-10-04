import React, { useState } from 'react';
import { RefreshCw, Database, Server, UserCheck, ChevronDown, Shield, Check } from 'lucide-react';
import type { SystemHealth, AuthUser } from '../types';

interface HeaderProps {
  title: string;
  subtitle: string;
  health: SystemHealth | null;
  isHealthLoading: boolean;
  onRefreshHealth: () => void;
  currentUser?: AuthUser | null;
  onSwitchPersona?: (email: string) => void;
}

export const Header: React.FC<HeaderProps> = ({
  title,
  subtitle,
  health,
  isHealthLoading,
  onRefreshHealth,
  currentUser,
  onSwitchPersona,
}) => {
  const [showPersonaMenu, setShowPersonaMenu] = useState(false);
  const isPgHealthy = health?.services?.database?.status === 'connected';
  const isRedisHealthy = health?.services?.redis?.status === 'connected';

  const personas = [
    { name: 'Platform Administrator', email: 'admin@transactiq.io', role: 'admin', desc: 'Universal platform and root infrastructure access (*)' },
    { name: 'Operations Lead', email: 'ops@transactiq.io', role: 'operations', desc: 'Settlements, reconciliation triage & webhook replay' },
    { name: 'Compliance Auditor', email: 'auditor@transactiq.io', role: 'auditor', desc: 'Read-only financial ledger and cryptographic audit inspection' },
    { name: 'SwiftPay Merchant Operator', email: 'admin@swiftpay.com', role: 'merchant', desc: 'Scoped to SwiftPay tenant payments & settlements' },
  ];

  return (
    <header className="top-header">
      {/* Title & Subtitle */}
      <div>
        <h1 style={{ fontSize: '20px', fontWeight: 700, color: '#fff' }}>
          {title}
        </h1>
        <p style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '2px' }}>
          {subtitle}
        </p>
      </div>

      {/* Live System Telemetry */}
      <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
        {/* PostgreSQL Status */}
        <div style={{
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
          padding: '6px 12px',
          borderRadius: 'var(--radius-md)',
          background: isPgHealthy ? 'rgba(16, 185, 129, 0.08)' : 'rgba(244, 63, 94, 0.08)',
          border: `1px solid ${isPgHealthy ? 'rgba(16, 185, 129, 0.25)' : 'rgba(244, 63, 94, 0.25)'}`,
          fontSize: '12px',
        }}>
          <Database size={14} color={isPgHealthy ? 'var(--success)' : 'var(--danger)'} />
          <span style={{ color: 'var(--text-secondary)' }}>PostgreSQL:</span>
          <span style={{ 
            fontWeight: 600, 
            color: isPgHealthy ? 'var(--success)' : 'var(--danger)',
            fontFamily: 'var(--font-mono)' 
          }}>
            {isPgHealthy ? `${health?.services?.database?.latency_ms ?? 12}ms` : 'Offline'}
          </span>
        </div>

        {/* Redis Status */}
        <div style={{
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
          padding: '6px 12px',
          borderRadius: 'var(--radius-md)',
          background: isRedisHealthy ? 'rgba(99, 102, 241, 0.08)' : 'rgba(245, 158, 11, 0.08)',
          border: `1px solid ${isRedisHealthy ? 'rgba(99, 102, 241, 0.25)' : 'rgba(245, 158, 11, 0.25)'}`,
          fontSize: '12px',
        }}>
          <Server size={14} color={isRedisHealthy ? 'var(--accent-primary)' : 'var(--warning)'} />
          <span style={{ color: 'var(--text-secondary)' }}>Redis:</span>
          <span style={{ 
            fontWeight: 600, 
            color: isRedisHealthy ? 'var(--accent-primary)' : 'var(--warning)',
            fontFamily: 'var(--font-mono)' 
          }}>
            {isRedisHealthy ? 'PONG' : 'Standby'}
          </span>
        </div>

        {/* Environment Tag */}
        <div className="badge badge-info">
          <div className="pulse-dot" />
          <span>Local Engine</span>
        </div>

        {/* Refresh Health Button */}
        <button
          onClick={onRefreshHealth}
          disabled={isHealthLoading}
          style={{
            background: 'rgba(255, 255, 255, 0.04)',
            border: '1px solid var(--border-subtle)',
            borderRadius: 'var(--radius-md)',
            padding: '8px 10px',
            color: 'var(--text-secondary)',
            cursor: 'pointer',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            transition: 'all 0.15s ease',
          }}
          title="Refresh backend status"
          onMouseEnter={(e) => { e.currentTarget.style.borderColor = 'var(--border-highlight)'; }}
          onMouseLeave={(e) => { e.currentTarget.style.borderColor = 'var(--border-subtle)'; }}
        >
          <RefreshCw size={14} className={isHealthLoading ? 'animate-spin' : ''} />
        </button>

        {/* User Persona & Role Switcher */}
        <div style={{ position: 'relative' }}>
          <button
            onClick={() => setShowPersonaMenu(!showPersonaMenu)}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              padding: '6px 14px',
              borderRadius: 'var(--radius-md)',
              background: 'linear-gradient(135deg, rgba(99, 102, 241, 0.15) 0%, rgba(139, 92, 246, 0.1) 100%)',
              border: '1px solid var(--border-accent)',
              color: '#fff',
              fontSize: '12px',
              fontWeight: 600,
              cursor: 'pointer',
              transition: 'all 0.2s ease',
            }}
          >
            <Shield size={14} color="var(--accent-primary)" />
            <span>{currentUser?.name ?? 'Platform Administrator'}</span>
            <span style={{
              fontSize: '10px',
              textTransform: 'uppercase',
              letterSpacing: '0.05em',
              padding: '2px 6px',
              borderRadius: '4px',
              background: 'rgba(99, 102, 241, 0.3)',
              color: 'var(--accent-light)',
            }}>
              {currentUser?.role ?? 'admin'}
            </span>
            <ChevronDown size={14} color="var(--text-muted)" />
          </button>

          {showPersonaMenu && (
            <div style={{
              position: 'absolute',
              top: '115%',
              right: 0,
              width: '320px',
              background: 'var(--bg-card)',
              border: '1px solid var(--border-subtle)',
              borderRadius: 'var(--radius-lg)',
              padding: '12px',
              boxShadow: '0 20px 40px rgba(0, 0, 0, 0.6)',
              zIndex: 100,
              display: 'flex',
              flexDirection: 'column',
              gap: '6px',
            }}>
              <div style={{
                fontSize: '11px',
                fontWeight: 700,
                color: 'var(--text-muted)',
                textTransform: 'uppercase',
                letterSpacing: '0.05em',
                padding: '4px 8px 8px',
                borderBottom: '1px solid var(--border-subtle)',
              }}>
                Select Institutional Persona
              </div>

              {personas.map((p) => {
                const isActive = (currentUser?.email ?? 'admin@transactiq.io') === p.email;
                return (
                  <button
                    key={p.email}
                    onClick={() => {
                      onSwitchPersona?.(p.email);
                      setShowPersonaMenu(false);
                    }}
                    style={{
                      display: 'flex',
                      alignItems: 'flex-start',
                      gap: '10px',
                      padding: '10px',
                      borderRadius: 'var(--radius-md)',
                      background: isActive ? 'rgba(99, 102, 241, 0.12)' : 'transparent',
                      border: `1px solid ${isActive ? 'var(--border-accent)' : 'transparent'}`,
                      color: '#fff',
                      textAlign: 'left',
                      cursor: 'pointer',
                      transition: 'all 0.15s ease',
                    }}
                    onMouseEnter={(e) => {
                      if (!isActive) e.currentTarget.style.background = 'rgba(255, 255, 255, 0.04)';
                    }}
                    onMouseLeave={(e) => {
                      if (!isActive) e.currentTarget.style.background = 'transparent';
                    }}
                  >
                    <div style={{
                      marginTop: '2px',
                      width: '18px',
                      height: '18px',
                      borderRadius: '50%',
                      background: isActive ? 'var(--accent-primary)' : 'rgba(255, 255, 255, 0.1)',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      flexShrink: 0,
                    }}>
                      {isActive ? <Check size={12} color="#fff" /> : <UserCheck size={12} color="var(--text-muted)" />}
                    </div>
                    <div>
                      <div style={{ fontSize: '13px', fontWeight: 600, color: isActive ? 'var(--accent-light)' : '#fff' }}>
                        {p.name}
                      </div>
                      <div style={{ fontSize: '11px', color: 'var(--text-muted)', marginTop: '2px' }}>
                        {p.desc}
                      </div>
                    </div>
                  </button>
                );
              })}
            </div>
          )}
        </div>
      </div>
    </header>
  );
};
