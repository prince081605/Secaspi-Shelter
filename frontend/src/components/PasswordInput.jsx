import { useState } from 'react';

// Eye / eye-off icon (Feather-style inline SVG) — replaces the old 🙈/👁️ emoji affordance, which
// rendered inconsistently across OSes. `off` = password is currently visible (shows the slashed eye
// meaning "click to hide").
function EyeIcon({ off }) {
  const common = {
    width: 18, height: 18, viewBox: '0 0 24 24', fill: 'none',
    stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round',
    'aria-hidden': true, focusable: false,
  };
  return off ? (
    <svg {...common}>
      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
      <line x1="1" y1="1" x2="23" y2="23" />
    </svg>
  ) : (
    <svg {...common}>
      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  );
}

// Password field with a built-in show/hide toggle. Drop-in for the auth pages: keeps the same
// `ui-input` styling and forwards standard input props (autoComplete, required, minLength, id...).
export default function PasswordInput({ value, onChange, ...inputProps }) {
  const [show, setShow] = useState(false);

  return (
    <div style={{ position: 'relative' }}>
      <input
        className="ui-input"
        style={{ paddingRight: '2.8rem' }}
        value={value}
        onChange={onChange}
        type={show ? 'text' : 'password'}
        {...inputProps}
      />
      <button
        type="button"
        onClick={() => setShow((v) => !v)}
        aria-label={show ? 'Hide password' : 'Show password'}
        aria-pressed={show}
        style={{
          position: 'absolute', right: '0.9rem', top: '50%', transform: 'translateY(-50%)',
          background: 'none', border: 'none', cursor: 'pointer', padding: 0,
          display: 'flex', alignItems: 'center', lineHeight: 1, color: 'var(--muted)',
        }}
      >
        <EyeIcon off={show} />
      </button>
    </div>
  );
}
