import { useState } from 'react';

export default function ConfirmButton({
  onConfirm,
  children,
  confirmLabel = 'Confirm?',
  className = 'dashBtn dashBtnDanger',
  ...rest
}) {
  const [confirming, setConfirming] = useState(false);

  if (confirming) {
    return (
      <span className="dashFlexRow" style={{ display: 'inline-flex' }}>
        <button
          type="button"
          className="dashBtn dashBtnDanger"
          onClick={() => {
            setConfirming(false);
            onConfirm();
          }}
        >
          {confirmLabel}
        </button>
        <button type="button" className="dashBtn" onClick={() => setConfirming(false)}>
          Cancel
        </button>
      </span>
    );
  }

  return (
    <button type="button" className={className} onClick={() => setConfirming(true)} {...rest}>
      {children}
    </button>
  );
}
