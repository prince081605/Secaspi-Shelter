import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { auth } from '../../lib/auth';
import AuthLayout from '../../components/AuthLayout';

export default function ResetPassword() {
  const navigate = useNavigate();
  const [params] = useSearchParams();

  // Pre-filled from the emailed link (?email=...&token=...); editable in case the user
  // landed here manually or pasted a token.
  const [email, setEmail] = useState(params.get('email') || '');
  const [token, setToken] = useState(params.get('token') || '');
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

  return (
    <AuthLayout
      title="Reset password"
      subtitle="Choose a new password for your account."
      footer={<a href="/login">Back to login</a>}
    >
      {error ? <div className="ui-error">{error}</div> : null}
      {success ? <div className="ui-success-msg">{success}</div> : null}

      <form onSubmit={onSubmit}>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Email</label>
          <input
            className="ui-input"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Reset token</label>
          <input
            className="ui-input"
            value={token}
            onChange={(e) => setToken(e.target.value)}
            required
            placeholder="From your reset email"
          />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">New password</label>
          <input
            className="ui-input"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            minLength={8}
          />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Confirm new password</label>
          <input
            className="ui-input"
            type="password"
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
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
