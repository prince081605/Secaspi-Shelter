import { useState } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import { auth } from '../../lib/auth';
import AuthLayout from '../../components/AuthLayout';
import PasswordInput from '../../components/PasswordInput';

export default function ResetPassword() {
  const navigate = useNavigate();
  const [params] = useSearchParams();

  // Taken straight from the emailed link (?email=...&token=...) and submitted as-is. The token is
  // never rendered or editable, so it can't be accidentally altered — the only place it lives is
  // the URL, which is where the reset email put it. Landing here without both means the link is
  // incomplete (see the guard below).
  const email = params.get('email') || '';
  const token = params.get('token') || '';
  const linkValid = Boolean(email && token);

  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  const onSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setSuccess('');

    if (password !== confirm) {
      setError('Passwords do not match.');
      return;
    }
    if (password.length < 8) {
      setError('Password must be at least 8 characters.');
      return;
    }

    setLoading(true);
    try {
      await auth.resetPassword(email, token, password);
      setSuccess('Your password has been reset. Redirecting to login…');
      setTimeout(() => navigate('/login', { replace: true }), 1500);
    } catch (err) {
      setError(err?.message || 'Reset failed. The link may have expired — request a new one.');
    } finally {
      setLoading(false);
    }
  };

  // No usable link — don't show a form the user can't complete; send them back to request a fresh one.
  if (!linkValid) {
    return (
      <AuthLayout
        title="Reset link incomplete"
        subtitle="This password reset link is missing information. Open the most recent link from your reset email, or request a new one."
        footer={<Link to="/login">Back to login</Link>}
      >
        <Link
          to="/forgot-password"
          className="ui-btn-primary"
          style={{ width: '100%', display: 'block', textAlign: 'center', textDecoration: 'none' }}
        >
          Request a new link
        </Link>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Reset password"
      subtitle="Choose a new password for your account."
      footer={<Link to="/login">Back to login</Link>}
    >
      {error ? <div className="ui-error">{error}</div> : null}
      {success ? <div className="ui-success-msg">{success}</div> : null}

      <form onSubmit={onSubmit}>
        <div className="ui-field">
          <label className="ui-label">Email</label>
          {/* Shown read-only so the user can confirm which account they're resetting; it comes
              from the link, not typed. */}
          <input className="ui-input" type="email" value={email} readOnly />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">New password</label>
          <PasswordInput
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="new-password"
            required
            minLength={8}
          />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Confirm new password</label>
          <PasswordInput
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            autoComplete="new-password"
            required
            minLength={8}
          />
        </div>
        <button className="ui-btn-primary" style={{ width: '100%' }} disabled={loading}>
          {loading ? 'Resetting…' : 'Reset password'}
        </button>
      </form>
    </AuthLayout>
  );
}
