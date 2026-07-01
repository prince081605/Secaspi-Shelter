import { useState } from 'react';
import { AlertTriangle } from 'lucide-react';

const REQUIRED_WORD = 'DELETE';

// Layout/positioning lives here since it's structural; colors and fonts are left to
// whatever page wraps this (currently only AnimalsAdmin.css, the sole consumer).
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
        <div className="confirm-overlay" onClick={reset}>
          <div className="confirm-panel" onClick={(e) => e.stopPropagation()}>
            <div className="confirm-icon" aria-hidden="true"><AlertTriangle size={22} /></div>
            <h2>Confirm deletion</h2>
            <p>{warningLabel}</p>
            <div className="confirm-actions">
              <button type="button" className="dashBtn" onClick={reset}>Cancel</button>
              <button type="button" className="dashBtn dashBtnDanger" onClick={() => setStep('type')}>
                Yes, continue
              </button>
            </div>
          </div>
        </div>
      )}

      {step === 'type' && (
        <div className="confirm-overlay" onClick={reset}>
          <div className="confirm-panel" onClick={(e) => e.stopPropagation()}>
            <div className="confirm-icon" aria-hidden="true"><AlertTriangle size={22} /></div>
            <h2>Type {REQUIRED_WORD} to confirm</h2>
            <p>This action is permanent. Type <strong>{REQUIRED_WORD}</strong> below to proceed.</p>
            <input
              className="ui-input confirm-type-input"
              placeholder={REQUIRED_WORD}
              aria-label={`Type ${REQUIRED_WORD} to confirm`}
              value={typed}
              onChange={(e) => setTyped(e.target.value)}
              autoFocus
            />
            <div className="confirm-actions">
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
