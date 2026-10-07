import { useEffect, useRef, useState } from 'react';
import { loadTurnstile } from '../lib/captcha';
import './HumanCheck.css';

/**
 * The anti-spam part of a public form: Cloudflare's "Verify you are human" box (when the
 * backend has a site key), plus a honeypot field people never see. Use it through
 * useHumanCheck(), which owns the token and honeypot state and builds the request fields.
 */
export default function HumanCheck({ siteKey, onToken, resetCount, honeypot, onHoneypot }) {
  const boxRef = useRef(null);
  const widgetIdRef = useRef(null);
  const [loadError, setLoadError] = useState('');

  // The latest onToken, so the widget (created once) always reports to the current form.
  const onTokenRef = useRef(onToken);
  useEffect(() => { onTokenRef.current = onToken; });

  useEffect(() => {
    if (!siteKey) return undefined;
    let alive = true;
    loadTurnstile()
      .then((turnstile) => {
        if (!alive || !boxRef.current) return;
        widgetIdRef.current = turnstile.render(boxRef.current, {
          sitekey: siteKey,
          theme: 'light',
          size: 'flexible',
          callback: (token) => onTokenRef.current(token),
          // A token lasts about five minutes; once it lapses the box asks again.
          'expired-callback': () => onTokenRef.current(''),
          'error-callback': () => onTokenRef.current(''),
        });
      })
      .catch(() => {
        if (alive) setLoadError('The "Verify you are human" check could not load. Check your internet connection and refresh the page.');
      });

    return () => {
      alive = false;
      if (widgetIdRef.current != null) {
        window.turnstile?.remove(widgetIdRef.current);
        widgetIdRef.current = null;
      }
    };
  }, [siteKey]);

  // Each token works once, so the form asks for a fresh check after every attempt.
  useEffect(() => {
    if (resetCount > 0 && widgetIdRef.current != null) window.turnstile?.reset(widgetIdRef.current);
  }, [resetCount]);

  return (
    <div className="human-check">
      {/* Honeypot: off-screen and skipped by keyboard and screen readers. Only bots fill it in. */}
      <div className="human-check-trap" aria-hidden="true">
        <label>
          Company website
          <input
            type="text"
            name="company_website"
            tabIndex={-1}
            autoComplete="off"
            value={honeypot}
            onChange={(e) => onHoneypot(e.target.value)}
          />
        </label>
      </div>
      {siteKey && <div ref={boxRef} className="human-check-box" />}
      {loadError && <p className="human-check-error" role="alert">{loadError}</p>}
    </div>
  );
}
