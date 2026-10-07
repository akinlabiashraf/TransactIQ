import React from 'react';
import { 
  LayoutDashboard, 
  ArrowLeftRight, 
  Scale, 
  GitCompare, 
  Webhook, 
  Landmark, 
  ShieldCheck,
  Terminal,
  BookOpen,
  ExternalLink,
  AlertOctagon,
} from 'lucide-react';

export type NavSection = 
  | 'overview' 
  | 'transactions' 
  | 'disputes'
  | 'ledger' 
  | 'reconciliation' 
  | 'webhooks' 
  | 'settlements' 
  | 'security'
  | 'sandbox';

interface SidebarProps {
  currentSection: NavSection;
  onSelectSection: (section: NavSection) => void;
}

export const Sidebar: React.FC<SidebarProps> = ({ currentSection, onSelectSection }) => {
  const navItems: { id: NavSection; label: string; icon: React.ReactNode; badge?: string }[] = [
    { id: 'overview', label: 'Overview', icon: <LayoutDashboard size={18} /> },
    { id: 'transactions', label: 'Transactions', icon: <ArrowLeftRight size={18} />, badge: 'FSM' },
    { id: 'disputes', label: 'Disputes & Claims', icon: <AlertOctagon size={18} />, badge: 'Escrow' },
    { id: 'ledger', label: 'Double-Entry Ledger', icon: <Scale size={18} /> },
    { id: 'reconciliation', label: 'Reconciliation', icon: <GitCompare size={18} /> },
    { id: 'webhooks', label: 'Webhooks', icon: <Webhook size={18} /> },
    { id: 'settlements', label: 'Settlements', icon: <Landmark size={18} /> },
    { id: 'security', label: 'Security & RBAC', icon: <ShieldCheck size={18} />, badge: 'Audit' },
    { id: 'sandbox', label: 'API Sandbox & Keys', icon: <Terminal size={18} />, badge: 'Test' },
  ];

  return (
    <aside className="sidebar">
      {/* Brand Header */}
      <div style={{ padding: '24px 24px 20px', borderBottom: '1px solid var(--border-subtle)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <div style={{
            width: '38px',
            height: '38px',
            borderRadius: '10px',
            background: 'linear-gradient(135deg, #6366f1 0%, #06b6d4 100%)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            boxShadow: '0 0 16px rgba(99, 102, 241, 0.4)',
            color: '#fff',
            fontWeight: 800,
            fontSize: '18px',
            fontFamily: 'var(--font-display)'
          }}>
            IQ
          </div>
          <div>
            <div style={{ fontSize: '18px', fontWeight: 800, letterSpacing: '-0.02em', color: '#fff', fontFamily: 'var(--font-display)' }}>
              Transact<span style={{ color: '#06b6d4' }}>IQ</span>
            </div>
            <div style={{ fontSize: '11px', color: 'var(--text-muted)', fontWeight: 500, letterSpacing: '0.04em' }}>
              PAYMENT INFRASTRUCTURE
            </div>
          </div>
        </div>
      </div>

      {/* Navigation List */}
      <div style={{ flex: 1, padding: '20px 14px', overflowY: 'auto' }}>
        <div style={{ 
          fontSize: '10px', 
          fontWeight: 700, 
          textTransform: 'uppercase', 
          letterSpacing: '0.08em', 
          color: 'var(--text-muted)', 
          padding: '0 12px 10px' 
        }}>
          Operations Core
        </div>
        
        <nav style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
          {navItems.map((item) => {
            const isActive = currentSection === item.id;
            return (
              <button
                key={item.id}
                onClick={() => onSelectSection(item.id)}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  width: '100%',
                  padding: '10px 12px',
                  borderRadius: 'var(--radius-md)',
                  border: '1px solid',
                  borderColor: isActive ? 'var(--border-accent)' : 'transparent',
                  background: isActive 
                    ? 'linear-gradient(90deg, rgba(99, 102, 241, 0.15) 0%, rgba(99, 102, 241, 0.05) 100%)' 
                    : 'transparent',
                  color: isActive ? '#fff' : 'var(--text-secondary)',
                  cursor: 'pointer',
                  textAlign: 'left',
                  fontSize: '13px',
                  fontWeight: isActive ? 600 : 500,
                  transition: 'all 0.15s ease',
                }}
                onMouseEnter={(e) => {
                  if (!isActive) {
                    e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.03)';
                    e.currentTarget.style.color = '#fff';
                  }
                }}
                onMouseLeave={(e) => {
                  if (!isActive) {
                    e.currentTarget.style.backgroundColor = 'transparent';
                    e.currentTarget.style.color = 'var(--text-secondary)';
                  }
                }}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                  <span style={{ color: isActive ? 'var(--accent-primary)' : 'inherit' }}>
                    {item.icon}
                  </span>
                  <span>{item.label}</span>
                </div>
                {item.badge && (
                  <span style={{
                    fontSize: '10px',
                    fontWeight: 700,
                    padding: '2px 6px',
                    borderRadius: '4px',
                    background: isActive ? 'rgba(99, 102, 241, 0.3)' : 'rgba(255, 255, 255, 0.06)',
                    color: isActive ? '#c7d2fe' : 'var(--text-muted)',
                    fontFamily: 'var(--font-mono)'
                  }}>
                    {item.badge}
                  </span>
                )}
              </button>
            );
          })}
        </nav>
      </div>

      {/* Interactive API Docs Link */}
      <div style={{ padding: '0 16px 12px' }}>
        <a 
          href="/docs" 
          target="_blank" 
          rel="noreferrer"
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '10px 14px',
            background: 'rgba(99, 102, 241, 0.08)',
            border: '1px solid rgba(99, 102, 241, 0.25)',
            borderRadius: 'var(--radius-md)',
            color: 'var(--accent-primary)',
            fontSize: '12px',
            fontWeight: 600,
            textDecoration: 'none',
            transition: 'all 0.15s ease',
          }}
          className="card-interactive"
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <BookOpen size={16} />
            <span>Interactive API Docs</span>
          </div>
          <ExternalLink size={13} />
        </a>
      </div>

      {/* Footer Info */}
      <div style={{ padding: '16px 20px', borderTop: '1px solid var(--border-subtle)', background: 'rgba(0,0,0,0.2)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
          <div style={{
            width: '32px',
            height: '32px',
            borderRadius: '50%',
            background: 'rgba(16, 185, 129, 0.15)',
            color: 'var(--success)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center'
          }}>
            <ShieldCheck size={16} />
          </div>
          <div style={{ minWidth: 0, flex: 1 }}>
            <div style={{ fontSize: '12px', fontWeight: 600, color: '#fff', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
              SwiftPay Retail Ltd
            </div>
            <div style={{ fontSize: '11px', color: 'var(--text-muted)' }}>
              MC-SWIFTPAY • NGN
            </div>
          </div>
        </div>
      </div>
    </aside>
  );
};
