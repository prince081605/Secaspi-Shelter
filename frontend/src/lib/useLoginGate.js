import { useCallback, useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { getAuthToken } from './api';

/**
 * Visit, Volunteer and Donate are public pages with a private action: anyone can read them and
 * fill the form in, but submitting writes a record against an account, so that one step needs a
 * login. This hook is that gate. It reports whether the visitor is signed in, sends them to
 * /login remembering the page they came from, and carries their half-filled form across the
 * trip — so logging in costs them the round trip and nothing else.
 *
 * The signed-in check reads the stored token rather than calling /api/user, because these pages
 * now serve anonymous traffic and an auth request per page load is a lot for what is only a
 * button label. A stale token therefore shows "Request visit" to someone whose session has
 * expired; the server still rejects the write, and `handleAuthError` turns that 401 into the
 * same redirect, so the label is optimistic but the gate is not.
 *
 * `pageKey` namespaces the saved draft — pass the route ('/visit'), so two gated pages left
 * half-filled in one session don't overwrite each other.
 *
 * Usage: read `gate.draft?.field` in your useState initialisers; the hook clears the draft for
 * you once mounted.
 */

const DRAFT_PREFIX = 'secaspi_draft:';

function readDraft(pageKey) {
  try {
    const raw = sessionStorage.getItem(DRAFT_PREFIX + pageKey);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

export default function useLoginGate(pageKey) {
  const navigate = useNavigate();
  const { pathname } = useLocation();

  const isAuthed = Boolean(getAuthToken());

  // The draft is read during the first render but cleared in an effect, deliberately in two
  // steps: a read that also removed the entry would lose it under StrictMode, whose double
  // render calls this initialiser twice and keeps the *second* result — by then an entry
  // consumed by the first call is gone. So the read stays pure and the effect does the clearing,
  // which leaves a later visit on a clean form rather than resurrecting something the visitor
  // typed and walked away from.
  const [draft] = useState(() => readDraft(pageKey));

  useEffect(() => {
    try {
      sessionStorage.removeItem(DRAFT_PREFIX + pageKey);
    } catch {
      // Storage unavailable — there was nothing to clear anyway.
    }
  }, [pageKey]);

  // Drafts live in sessionStorage, not localStorage: an abandoned half-filled form is worth
  // keeping for the login detour, not for weeks.
  const askToLogin = useCallback((values) => {
    if (values) {
      try {
        sessionStorage.setItem(DRAFT_PREFIX + pageKey, JSON.stringify(values));
      } catch {
        // Private mode or a full quota — losing the draft is not worth blocking the login on.
      }
    }
    navigate('/login', { state: { from: pathname } });
  }, [navigate, pathname, pageKey]);

  // For the stale-token case: a submit that comes back 401 is a login prompt, not an error
  // message. Returns true when it has taken over, so callers can skip their own error handling.
  const handleAuthError = useCallback((err, values) => {
    if (err?.status !== 401) return false;
    askToLogin(values);
    return true;
  }, [askToLogin]);

  return { isAuthed, draft, askToLogin, handleAuthError };
}
