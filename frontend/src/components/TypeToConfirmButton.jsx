import { useState } from 'react';

const REQUIRED_WORD = 'DELETE';

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

  if (step === 'type') {
    return (
      <span className="dashFlexRow" style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
        <input
          className="ui-input"
          style={{ maxWidth: 140 }}
          placeholder={`Type ${REQUIRED_WORD}`}
          aria-label={`Type ${REQUIRED_WORD} to confirm`}
          value={typed}
          onChange={(e) => setTyped(e.target.value)}
          autoFocus
        />
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
        <button type="button" className="dashBtn" onClick={reset}>
          Cancel
        </button>
      </span>
    );
  }

  if (step === 'warn') {
    return (
      <span className="dashFlexRow" style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
        <span className="ui-error" style={{ margin: 0 }}>{warningLabel}</span>
        <button type="button" className="dashBtn dashBtnDanger" onClick={() => setStep('type')}>
          Yes, continue
        </button>
        <button type="button" className="dashBtn" onClick={reset}>
          Cancel
        </button>
      </span>
    );
  }

  return (
    <button type="button" className={className} onClick={() => setStep('warn')} {...rest}>
      {children}
    </button>
  );
}
