import { useState, useEffect } from 'react';
import { Sidebar, type NavSection } from './components/Sidebar';
import { Header } from './components/Header';
import { OverviewView } from './views/OverviewView';
import { TransactionsView } from './views/TransactionsView';
import { LedgerView } from './views/LedgerView';
import { ReconciliationView } from './views/ReconciliationView';
import { WebhooksView } from './views/WebhooksView';
import { SettlementsView } from './views/SettlementsView';
import { SecurityView } from './views/SecurityView';
import { SandboxView } from './views/SandboxView';
import { apiService } from './services/api';
import type { 
  SystemHealth, 
  Transaction, 
  LedgerAccount, 
  LedgerEntry, 
  WebhookItem, 
  ReconciliationSummary, 
  ApiKeyItem,
  AuthUser 
} from './types';

export function App() {
  const [currentSection, setCurrentSection] = useState<NavSection>('overview');
  const [health, setHealth] = useState<SystemHealth | null>(null);
  const [isHealthLoading, setIsHealthLoading] = useState(false);
  const [currentUser, setCurrentUser] = useState<AuthUser | null>(() => {
    return apiService.getStoredUser() || {
      id: 'admin-01',
      name: 'Platform Administrator',
      email: 'admin@transactiq.io',
      role: 'admin',
      role_name: 'Administrator',
    };
  });

  const handleSwitchPersona = async (email: string) => {
    try {
      const res = await apiService.login(email, 'Password123!');
      if (res.data?.user) {
        setCurrentUser(res.data.user);
      }
    } catch (err) {
      console.error('Failed to switch persona:', err);
    }
  };

  // Initial State matching our seeded database records
  const [transactions, setTransactions] = useState<Transaction[]>([
    {
      id: '01a0c3e1-3048-7279-82a2-66b6b78f3ded',
      reference: 'TXN-20260921-100201',
      merchant_id: '01a0c3e0-62cf-7148-92ac-96e816405f08',
      merchant_name: 'SwiftPay Retail Enterprises',
      customer_email: 'chinedu.okafor@example.com',
      amount: 5000000, // ₦50,000.00
      fee_amount: 75000, // ₦750.00 (1.5%)
      net_amount: 4925000, // ₦49,250.00
      currency: 'NGN',
      status: 'SUCCESS',
      payment_method: 'CARD',
      idempotency_key: 'idemp-init-sample-01',
      provider: 'SIMULATED_GATEWAY',
      created_at: new Date(Date.now() - 3600000 * 2).toISOString(),
    },
    {
      id: '01a0c3e1-4567-8910-bcde-123456789abc',
      reference: 'TXN-20260921-100202',
      merchant_id: '01a0c3e0-62cf-7148-92ac-96e816405f08',
      merchant_name: 'SwiftPay Retail Enterprises',
      customer_email: 'aisha.bello@example.com',
      amount: 12000000, // ₦120,000.00
      fee_amount: 180000,
      net_amount: 11820000,
      currency: 'NGN',
      status: 'SUCCESS',
      payment_method: 'BANK_TRANSFER',
      idempotency_key: 'idemp-init-sample-02',
      provider: 'SIMULATED_GATEWAY',
      created_at: new Date(Date.now() - 3600000).toISOString(),
    }
  ]);

  const [ledgerAccounts, setLedgerAccounts] = useState<LedgerAccount[]>([
    {
      account_number: 'ACC-PLATFORM-REVENUE',
      name: 'TransactIQ Platform Revenue',
      type: 'REVENUE',
      classification: 'PLATFORM_FEE_REVENUE',
      currency: 'NGN',
      balance: 255000, // ₦2,550.00
      owner: 'PLATFORM',
    },
    {
      account_number: 'ACC-PROVIDER-CLEARING',
      name: 'Payment Gateway Provider Clearing',
      type: 'ASSET',
      classification: 'PROVIDER_CLEARING',
      currency: 'NGN',
      balance: 17000000, // ₦170,000.00
      owner: 'PLATFORM',
    },
    {
      account_number: 'ACC-SWIFT-AVAIL',
      name: 'SwiftPay Available Balance',
      type: 'LIABILITY',
      classification: 'MERCHANT_AVAILABLE',
      currency: 'NGN',
      balance: 16745000, // ₦167,450.00
      owner: 'SwiftPay Retail Enterprises',
    },
    {
      account_number: 'ACC-SWIFT-PEND',
      name: 'SwiftPay Pending Settlement',
      type: 'LIABILITY',
      classification: 'MERCHANT_PENDING',
      currency: 'NGN',
      balance: 0,
      owner: 'SwiftPay Retail Enterprises',
    }
  ]);

  const [ledgerEntries, setLedgerEntries] = useState<LedgerEntry[]>([
    {
      id: 'entry-01',
      reference: 'JRN-20260921-00001',
      entry_type: 'PAYMENT_CAPTURED',
      debit_account: 'ACC-PROVIDER-CLEARING',
      credit_account: 'ACC-SWIFT-AVAIL',
      amount: 4925000,
      currency: 'NGN',
      description: 'Capture payment for TXN-20260921-100201',
      created_at: new Date(Date.now() - 3600000 * 2).toISOString(),
    },
    {
      id: 'entry-02',
      reference: 'JRN-20260921-00002',
      entry_type: 'PLATFORM_FEE',
      debit_account: 'ACC-PROVIDER-CLEARING',
      credit_account: 'ACC-PLATFORM-REVENUE',
      amount: 75000,
      currency: 'NGN',
      description: 'Platform fee for TXN-20260921-100201',
      created_at: new Date(Date.now() - 3600000 * 2).toISOString(),
    }
  ]);

  const [webhooks, setWebhooks] = useState<WebhookItem[]>([
    {
      id: 'wh-01',
      event_type: 'payment.success',
      endpoint_url: 'https://webhook.site/test-swiftpay',
      signature: 'sha256=d7a8fbb307d7809469ca933b02d82941ef10e1112b3fffe44a99144bd31f4c4e',
      attempts: 1,
      max_attempts: 5,
      status: 'DELIVERED',
      response_status: 200,
      created_at: new Date(Date.now() - 3600000 * 2).toISOString(),
    }
  ]);

  const reconciliationRuns: ReconciliationSummary[] = [
    {
      run_reference: 'REC-20260921-001',
      provider: 'SIMULATED_GATEWAY',
      reconciliation_date: '2026-09-21',
      total_records: 124,
      matched_records: 124,
      mismatched_records: 0,
      status: 'COMPLETED',
    }
  ];

  const apiKeys: ApiKeyItem[] = [
    {
      type: 'LIVE',
      public_key: 'tiq_live_pub_Fp6Wc50bYe6sFAt081ewvb2z',
      secret_preview: 'tiq_...a7dOIb',
      scopes: ['payments:read', 'payments:write', 'refunds:write'],
      status: 'ACTIVE',
    },
    {
      type: 'TEST',
      public_key: 'tiq_test_pub_FEoEOwkZAfo3nJCH2zlRf2uM',
      secret_preview: 'tiq_...3rOrV5',
      scopes: ['payments:read', 'payments:write'],
      status: 'ACTIVE',
    }
  ];

  const activeApiKey = apiKeys.find(k => k.type === 'TEST')?.public_key || apiKeys[0]?.public_key || 'tiq_live_pub_Fp6Wc50bYe6sFAt081ewvb2z';

  // Fetch backend telemetry
  const loadHealth = async () => {
    setIsHealthLoading(true);
    const data = await apiService.getHealth();
    setHealth(data);
    setIsHealthLoading(false);
  };

  useEffect(() => {
    loadHealth();
    const interval = setInterval(loadHealth, 20000);
    return () => clearInterval(interval);
  }, []);

  const handlePaymentSimulated = (
    newTxn: Transaction,
    newEntries: LedgerEntry[],
    newWebhook: WebhookItem
  ) => {
    setTransactions(prev => [newTxn, ...prev]);
    if (newEntries.length > 0) {
      setLedgerEntries(prev => [...newEntries, ...prev]);
      // Update account balances
      setLedgerAccounts(prev => prev.map(acc => {
        if (acc.account_number === 'ACC-PROVIDER-CLEARING') {
          return { ...acc, balance: acc.balance + newTxn.amount };
        }
        if (acc.account_number === 'ACC-SWIFT-AVAIL') {
          return { ...acc, balance: acc.balance + newTxn.net_amount };
        }
        if (acc.account_number === 'ACC-PLATFORM-REVENUE') {
          return { ...acc, balance: acc.balance + newTxn.fee_amount };
        }
        return acc;
      }));
    }
    if (newWebhook) {
      setWebhooks(prev => [newWebhook, ...prev]);
    }
  };

  const getSectionTitle = () => {
    switch (currentSection) {
      case 'overview': return { title: 'Operational Overview', subtitle: 'Platform-wide telemetry, transaction metrics, and state machine health' };
      case 'transactions': return { title: 'Payment Transactions', subtitle: 'Audit log of all payment requests, state transitions, and idempotency keys' };
      case 'ledger': return { title: 'Double-Entry General Ledger', subtitle: 'Chart of accounts and balanced debit/credit financial entries' };
      case 'reconciliation': return { title: 'Automated Reconciliation', subtitle: 'Internal vs provider clearing file comparison and discrepancy matrix' };
      case 'webhooks': return { title: 'Webhook Delivery Engine', subtitle: 'Cryptographically signed HMAC notifications and retry attempt history' };
      case 'settlements': return { title: 'Merchant Settlements', subtitle: 'T+1 payout batching, platform fee deductions, and clearing ledger' };
      case 'security': return { title: 'Enterprise Security & RBAC', subtitle: 'Tamper-evident audit ledger, real-time risk heuristics, and institutional RBAC matrix' };
      case 'sandbox': return { title: 'API Keys & Payment Sandbox', subtitle: 'Interactive transaction simulator with idempotency verification' };
    }
  };

  const { title, subtitle } = getSectionTitle();

  return (
    <div className="app-container">
      <Sidebar 
        currentSection={currentSection} 
        onSelectSection={setCurrentSection} 
      />

      <div className="main-content">
        <Header 
          title={title}
          subtitle={subtitle}
          health={health}
          isHealthLoading={isHealthLoading}
          onRefreshHealth={loadHealth}
          currentUser={currentUser}
          onSwitchPersona={handleSwitchPersona}
        />

        <main style={{ flex: 1 }}>
          {currentSection === 'overview' && (
            <OverviewView 
              transactions={transactions}
              onNavigate={setCurrentSection}
              onTriggerTestPayment={() => setCurrentSection('sandbox')}
            />
          )}

          {currentSection === 'transactions' && (
            <TransactionsView 
              transactions={transactions}
              onOpenSandbox={() => setCurrentSection('sandbox')}
            />
          )}

          {currentSection === 'ledger' && (
            <LedgerView 
              accounts={ledgerAccounts}
              entries={ledgerEntries}
              apiKey={activeApiKey}
            />
          )}

          {currentSection === 'reconciliation' && (
            <ReconciliationView 
              runs={reconciliationRuns}
              apiKey={activeApiKey}
            />
          )}

          {currentSection === 'webhooks' && (
            <WebhooksView 
              webhooks={webhooks}
            />
          )}

          {currentSection === 'settlements' && (
            <SettlementsView apiKey={activeApiKey} />
          )}

          {currentSection === 'security' && (
            <SecurityView apiKey={activeApiKey} />
          )}

          {currentSection === 'sandbox' && (
            <SandboxView 
              apiKeys={apiKeys}
              onPaymentSimulated={handlePaymentSimulated}
            />
          )}
        </main>
      </div>
    </div>
  );
}

export default App;
