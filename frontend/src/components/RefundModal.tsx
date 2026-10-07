import React, { useState } from 'react';
import { X, RotateCcw, AlertTriangle, CheckCircle2 } from 'lucide-react';
import { apiService } from '../services/api';
import type { Transaction } from '../types';

interface RefundModalProps {
  transaction: Transaction;
  apiKey?: string;
  onClose: () => void;
  onSuccess: () => void;
}

export const RefundModal: React.FC<RefundModalProps> = ({
  transaction,
  apiKey = 'tiq_live_swiftpay_test_key_001',
  onClose,
  onSuccess,
}) => {
  const [refundType, setRefundType] = useState<'FULL' | 'PARTIAL'>('FULL');
  const [partialAmountKobo, setPartialAmountKobo] = useState<number>(transaction.amount);
  const [reason, setReason] = useState<string>('CUSTOMER_REQUEST');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const maxRefundable = transaction.amount; // In a full implementation, max refundable is passed

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setError(null);
    setSuccessMessage(null);

    try {
      const amountToSend = refundType === 'FULL' ? undefined : partialAmountKobo;
      const res = await apiService.issueRefund(apiKey, transaction.reference, amountToSend, reason);
      setSuccessMessage(res.message || 'Refund successfully processed.');
      setTimeout(() => {
        onSuccess();
        onClose();
      }, 1500);
    } catch (err: any) {
      setError(err.message || 'Failed to process refund.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
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
      zIndex: 1000,
      padding: '16px',
    }}>
      <div style={{
        backgroundColor: 'var(--bg-card, #121622)',
        border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
        borderRadius: '16px',
        width: '100%',
        maxWidth: '520px',
        boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.5)',
        overflow: 'hidden',
      }}>
        {/* Modal Header */}
        <div style={{
          padding: '20px 24px',
          borderBottom: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.08))',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <div style={{
              width: '36px',
              height: '36px',
              borderRadius: '10px',
              backgroundColor: 'rgba(239, 68, 68, 0.12)',
              color: '#ef4444',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}>
              <RotateCcw size={18} />
            </div>
            <div>
              <h3 style={{ margin: 0, fontSize: '17px', fontWeight: 600, color: 'var(--text-primary, #fff)' }}>
                Issue Financial Refund
              </h3>
              <p style={{ margin: 0, fontSize: '13px', color: 'var(--text-secondary, #94a3b8)' }}>
                Reference: <code style={{ color: '#818cf8' }}>{transaction.reference}</code>
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            disabled={isSubmitting}
            style={{
              background: 'transparent',
              border: 'none',
              color: 'var(--text-secondary, #94a3b8)',
              cursor: 'pointer',
              padding: '6px',
              borderRadius: '8px',
            }}
          >
            <X size={20} />
          </button>
        </div>

        {/* Modal Body */}
        <form onSubmit={handleSubmit} style={{ padding: '24px' }}>
          {error && (
            <div style={{
              padding: '12px 16px',
              backgroundColor: 'rgba(239, 68, 68, 0.12)',
              border: '1px solid rgba(239, 68, 68, 0.3)',
              borderRadius: '10px',
              color: '#f87171',
              fontSize: '13px',
              display: 'flex',
              alignItems: 'center',
              gap: '10px',
              marginBottom: '20px',
            }}>
              <AlertTriangle size={18} style={{ flexShrink: 0 }} />
              <span>{error}</span>
            </div>
          )}

          {successMessage && (
            <div style={{
              padding: '12px 16px',
              backgroundColor: 'rgba(16, 185, 129, 0.12)',
              border: '1px solid rgba(16, 185, 129, 0.3)',
              borderRadius: '10px',
              color: '#34d399',
              fontSize: '13px',
              display: 'flex',
              alignItems: 'center',
              gap: '10px',
              marginBottom: '20px',
            }}>
              <CheckCircle2 size={18} style={{ flexShrink: 0 }} />
              <span>{successMessage}</span>
            </div>
          )}

          {/* Refund Type Selection */}
          <div style={{ marginBottom: '20px' }}>
            <label style={{ display: 'block', fontSize: '13px', fontWeight: 500, color: 'var(--text-secondary, #94a3b8)', marginBottom: '8px' }}>
              Refund Mode
            </label>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
              <button
                type="button"
                onClick={() => {
                  setRefundType('FULL');
                  setPartialAmountKobo(maxRefundable);
                }}
                style={{
                  padding: '12px',
                  borderRadius: '10px',
                  border: refundType === 'FULL' ? '1px solid #6366f1' : '1px solid var(--border-subtle, rgba(255,255,255,0.1))',
                  backgroundColor: refundType === 'FULL' ? 'rgba(99, 102, 241, 0.12)' : 'transparent',
                  color: refundType === 'FULL' ? '#a5b4fc' : 'var(--text-secondary, #94a3b8)',
                  cursor: 'pointer',
                  fontWeight: 600,
                  fontSize: '13px',
                  textAlign: 'center',
                }}
              >
                Full Refund ({apiService.formatMoney(maxRefundable, transaction.currency)})
              </button>

              <button
                type="button"
                onClick={() => setRefundType('PARTIAL')}
                style={{
                  padding: '12px',
                  borderRadius: '10px',
                  border: refundType === 'PARTIAL' ? '1px solid #6366f1' : '1px solid var(--border-subtle, rgba(255,255,255,0.1))',
                  backgroundColor: refundType === 'PARTIAL' ? 'rgba(99, 102, 241, 0.12)' : 'transparent',
                  color: refundType === 'PARTIAL' ? '#a5b4fc' : 'var(--text-secondary, #94a3b8)',
                  cursor: 'pointer',
                  fontWeight: 600,
                  fontSize: '13px',
                  textAlign: 'center',
                }}
              >
                Partial Refund
              </button>
            </div>
          </div>

          {/* Custom Amount (if Partial) */}
          {refundType === 'PARTIAL' && (
            <div style={{ marginBottom: '20px' }}>
              <label style={{ display: 'block', fontSize: '13px', fontWeight: 500, color: 'var(--text-secondary, #94a3b8)', marginBottom: '8px' }}>
                Partial Refund Amount (₦)
              </label>
              <input
                type="number"
                step="0.01"
                min="1.00"
                max={(maxRefundable / 100).toString()}
                value={partialAmountKobo / 100}
                onChange={(e) => setPartialAmountKobo(Math.round(parseFloat(e.target.value || '0') * 100))}
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
                required
              />
              <span style={{ fontSize: '12px', color: 'var(--text-muted, #64748b)', marginTop: '4px', display: 'block' }}>
                Max refundable: {apiService.formatMoney(maxRefundable, transaction.currency)}
              </span>
            </div>
          )}

          {/* Reason Selection */}
          <div style={{ marginBottom: '24px' }}>
            <label style={{ display: 'block', fontSize: '13px', fontWeight: 500, color: 'var(--text-secondary, #94a3b8)', marginBottom: '8px' }}>
              Refund Reason
            </label>
            <select
              value={reason}
              onChange={(e) => setReason(e.target.value)}
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
              <option value="CUSTOMER_REQUEST" style={{ background: '#121622' }}>Customer Requested Refund</option>
              <option value="DUPLICATE_CHARGE" style={{ background: '#121622' }}>Accidental Duplicate Charge</option>
              <option value="FRAUDULENT" style={{ background: '#121622' }}>Suspected Fraudulent Activity</option>
              <option value="ORDER_CANCELLED" style={{ background: '#121622' }}>Merchant Order Cancelled</option>
            </select>
          </div>

          {/* Footer Actions */}
          <div style={{ display: 'flex', gap: '12px', justifyContent: 'flex-end' }}>
            <button
              type="button"
              onClick={onClose}
              disabled={isSubmitting}
              style={{
                padding: '10px 18px',
                borderRadius: '8px',
                border: '1px solid var(--border-subtle, rgba(255, 255, 255, 0.1))',
                backgroundColor: 'transparent',
                color: 'var(--text-secondary, #94a3b8)',
                cursor: 'pointer',
                fontSize: '14px',
                fontWeight: 500,
              }}
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSubmitting}
              style={{
                padding: '10px 20px',
                borderRadius: '8px',
                border: 'none',
                background: 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)',
                color: '#fff',
                cursor: isSubmitting ? 'not-allowed' : 'pointer',
                fontSize: '14px',
                fontWeight: 600,
                display: 'flex',
                alignItems: 'center',
                gap: '8px',
                boxShadow: '0 4px 12px rgba(239, 68, 68, 0.25)',
              }}
            >
              {isSubmitting ? 'Processing...' : 'Confirm Refund'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
