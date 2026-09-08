import { useCallback, useEffect, useRef, useState } from 'react';
import { AlertTriangle, HelpCircle } from 'lucide-react';
import { ConfirmContext } from '../lib/useConfirm';
import './ConfirmDialog.css';

/*
 * A single confirmation dialog for every admin write.
 *
 * Admin work is not undoable from the UI — adding an animal, approving an adoption, verifying
 * a donation and deleting an expense all land in the database the moment the button is hit,
 * and several of them notify the applicant by email. So every one of them asks first, through
 * this one dialog, instead of some pages guarding a delete and the rest firing on a single
 * click.
 *
 * Handlers reach it through useConfirm (see lib/useConfirm.js), which reads as a guard clause:
 *
 *   const confirm = useConfirm();
 *   ...
 *   if (!(await confirm({ title: 'Add this dog?', confirmLabel: 'Add dog' }))) return;
 *
 * The provider is mounted once in AppRouter, so nested modules (IntakesAdmin inside
 * AnimalsAdmin, AdoptionRequestRows inside AdoptionRequestsAdmin) get it for free.
 */

export default function ConfirmProvider({ children }) {
  const [request, setRequest] = useState(null);
  const confirmBtnRef = useRef(null);
  // The awaiting handler's resolve lives in a ref, not in state: StrictMode may run a state
  // updater twice, and answering the same question twice is not something to leave to luck.
  const pendingRef = useRef(null);
  // Whatever the admin was on before the dialog stole focus, so Escape/Cancel puts them back
  // on the button they came from instead of at the top of the document.
  const restoreFocusRef = useRef(null);

  const confirm = useCallback(
    (options = {}) =>
      new Promise((resolve) => {
        const normalized = typeof options === 'string' ? { message: options } : options || {};
        // A second question while one is open would orphan the first handler's promise.
        pendingRef.current?.(false);
        pendingRef.current = resolve;
        restoreFocusRef.current = document.activeElement;
        setRequest(normalized);
      }),
    [],
  );

  const settle = useCallback((answer) => {
    const resolve = pendingRef.current;
    pendingRef.current = null;
    setRequest(null);
    const target = restoreFocusRef.current;
    restoreFocusRef.current = null;
    if (target?.isConnected) target.focus?.();
    resolve?.(answer);
  }, []);

  const open = Boolean(request);

  useEffect(() => {
    if (!open) return undefined;
    confirmBtnRef.current?.focus();
    const onKeyDown = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        settle(false);
      }
    };
    document.addEventListener('keydown', onKeyDown);
    // The page behind a modal should not scroll under it.
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [open, settle]);

  // A question still open when the provider goes away is a promise nobody resolves, which
  // would hang its handler forever.
  useEffect(
    () => () => {
      const resolve = pendingRef.current;
      pendingRef.current = null;
      resolve?.(false);
    },
    [],
  );

  const {
    title = 'Are you sure?',
    message = '',
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    tone = 'default',
    summary = null,
  } = request ?? {};

  const danger = tone === 'danger';
  const rows = (summary ?? []).filter((row) => row && row.value !== '' && row.value != null);

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      {open && (
        <div className="confirm-overlay" onClick={() => settle(false)}>
          <div
            className="confirm-panel"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="confirm-dialog-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className={`confirm-icon ${danger ? '' : 'confirm-icon-default'}`} aria-hidden="true">
              {danger ? <AlertTriangle size={22} /> : <HelpCircle size={22} />}
            </div>
            <h2 id="confirm-dialog-title">{title}</h2>
            {message && <p>{message}</p>}
            {rows.length > 0 && (
              <dl className="confirm-summary">
                {rows.map((row) => (
                  <div className="confirm-summary-row" key={row.label}>
                    <dt>{row.label}</dt>
                    <dd>{row.value}</dd>
                  </div>
                ))}
              </dl>
            )}
            <div className="confirm-actions">
              <button type="button" className="dashBtn" onClick={() => settle(false)}>
                {cancelLabel}
              </button>
              <button
                type="button"
                ref={confirmBtnRef}
                className={`dashBtn ${danger ? 'dashBtnDanger' : 'dashBtnPrimary'}`}
                onClick={() => settle(true)}
              >
                {confirmLabel}
              </button>
            </div>
          </div>
        </div>
      )}
    </ConfirmContext.Provider>
  );
}
