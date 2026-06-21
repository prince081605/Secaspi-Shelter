import { useState } from 'react';

const REQUIRED_WORD = 'DELETE';

const overlayStyle = {
  position: 'fixed',
  inset: 0,
  background: 'rgba(15, 23, 42, 0.55)',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  zIndex: 1000,
};

const modalStyle = {
  background: '#fff',
  borderRadius: 'var(--radius, 12px)',
  boxShadow: '0 20px 40px rgba(0,0,0,0.25)',
  padding: 24,
  width: '90%',
  maxWidth: 380,
};

export default function TypeToConfirmButton({
  onConfirm,
  children,
  warningLabel = 'Are you sure? This cannot be undone.',
  className = 'dashBtn dashBtnDanger',
  ...rest
}) {
  const [step, setStep] = useState('idle');
  const [typed, setTyped] = useState('');

  const reset = () => {
    setStep('idle');
    setTyped('');
  };

  return (
    <>
      <button type="button" className={className} onClick={() => setStep('warn')} {...rest}>
        {children}
      </button>

      {step === 'warn' && (
        <div style={overlayStyle} onClick={reset}>
          <div style={modalStyle} onClick={(e) => e.stopPropagation()}>
            <div style={{ fontWeight: 600, marginBottom: 8 }}>Confirm deletion</div>
            <p style={{ marginTop: 0, marginBottom: 16 }}>{warningLabel}</p>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
              <button type="button" className="dashBtn" onClick={reset}>Cancel</button>
              <button type="button" className="dashBtn dashBtnDanger" onClick={() => setStep('type')}>
                Yes, continue
              </button>
            </div>
          </div>
        </div>
      )}

      {step === 'type' && (
        <div style={overlayStyle} onClick={reset}>
          <div style={modalStyle} onClick={(e) => e.stopPropagation()}>
            <div style={{ fontWeight: 600, marginBottom: 8 }}>Type {REQUIRED_WORD} to confirm</div>
            <p style={{ marginTop: 0, marginBottom: 12 }}>
              This action is permanent. Type <strong>{REQUIRED_WORD}</strong> below to proceed.
            </p>
            <input
              className="ui-input"
              style={{ width: '100%', marginBottom: 16 }}
              placeholder={REQUIRED_WORD}
              aria-label={`Type ${REQUIRED_WORD} to confirm`}
              value={typed}
              onChange={(e) => setTyped(e.target.value)}
              autoFocus
            />
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
              <button type="button" className="dashBtn" onClick={reset}>Cancel</button>
              <button
                type="button"
                className="dashBtn dashBtnDanger"
                disabled={typed !== REQUIRED_WORD}
                onClick={() => {
                  reset();
                  onConfirm();
                }}
              >
                Confirm delete
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
