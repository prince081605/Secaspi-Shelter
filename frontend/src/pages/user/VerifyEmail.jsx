import { useEffect, useRef, useState } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
import { auth } from '../../lib/auth';
import AuthLayout from '../../components/AuthLayout';

// Landing page for the link in the verification email (?email=...&token=...). It verifies on
// load and shows the outcome; if the link is bad or expired, the user can request a fresh one.
export default function VerifyEmail() {
  const [params] = useSearchParams();
  const email = params.get('email') || '';
  const token = params.get('token') || '';

  const [status, setStatus] = useState('verifying'); // 'verifying' | 'success' | 'error'
  const [message, setMessage] = useState('');

  const [resending, setResending] = useState(false);
  const [resendMsg, setResendMsg] = useState('');

  // React 18 StrictMode double-invokes effects in dev; guard so we only verify once.
  const ran = useRef(false);

  useEffect(() => {
    if (ran.current) return;
    ran.current = true;

    if (!email || !token) {
      setStatus('error');
      setMessage('This verification link is incomplete. Use the button in your email, or resend it below.');
      return;
    }

    auth
      .verifyEmail(email, token)
      .then((data) => {
        setStatus('success');
        setMessage(data?.message || 'Email verified. You can now log in.');
      })
      .catch((err) => {
        setStatus('error');
        setMessage(err?.message || 'This verification link is invalid or has expired.');
      });
  }, [email, token]);

  const onResend = async () => {
    setResending(true);
    setResendMsg('');
    try {
      await auth.resendVerification(email);
      setResendMsg('If that account is unverified, a new link is on its way. Check your inbox and spam.');
    } catch (err) {
      setResendMsg(err?.message || 'Could not resend right now. Try again shortly.');
    } finally {
      setResending(false);
    }
  };

  return (
    <AuthLayout
      title="Verify your email"
      subtitle={
        status === 'verifying'
          ? 'Confirming your email address…'
          : status === 'success'
            ? 'Your email is confirmed.'
            : 'We couldn’t confirm this link.'
      }
      footer={<Link to="/login">Back to login</Link>}
    >
      {status === 'verifying' ? <p className="ui-muted">Just a moment…</p> : null}

      {status === 'success' ? (
        <>
          <div className="ui-success-msg" style={{ marginBottom: '1rem' }}>{message}</div>
          <Link to="/login" className="ui-btn-primary" style={{ width: '100%', display: 'block', textAlign: 'center', textDecoration: 'none' }}>
            Continue to login
          </Link>
        </>
      ) : null}

      {status === 'error' ? (
        <>
          <div className="ui-error" style={{ marginBottom: '1rem' }}>{message}</div>
          {resendMsg ? <div className="ui-success-msg" style={{ marginBottom: '0.75rem' }}>{resendMsg}</div> : null}
          {email ? (
            <button
              type="button"
              className="ui-btn-primary"
              style={{ width: '100%' }}
              onClick={onResend}
              disabled={resending}
            >
              {resending ? 'Sending…' : 'Resend verification email'}
            </button>
          ) : null}
        </>
      ) : null}
    </AuthLayout>
  );
}
