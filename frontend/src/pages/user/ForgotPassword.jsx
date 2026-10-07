import { useState } from 'react';
import { Link } from 'react-router-dom';
import { auth } from '../../lib/auth';
import AuthLayout from '../../components/AuthLayout';
import useHumanCheck from '../../lib/useHumanCheck';

export default function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [devToken, setDevToken] = useState('');
  const human = useHumanCheck();

  const onSubmit = async (e) => {
    e.preventDefault();
    if (!human.ready) {
      setError(human.notReadyMessage);
      return;
    }
    setLoading(true);
    setError('');
    setSuccess('');
    setDevToken('');

    try {
      const res = await auth.forgotPassword(email, human.fields);
      setSuccess(res.message || 'Request submitted');
      if (res.token) setDevToken(res.token);
    } catch (err) {
      setError(err.message || 'Request failed');
    } finally {
      // The form stays on screen either way, so a second request needs a fresh check.
      human.reset();
      setLoading(false);
    }
  };

  return (
    <AuthLayout
      title="Forgot password"
      subtitle="Enter your email and we'll send you a reset link."
      footer={<Link to="/login">Back to login</Link>}
    >
      {error ? <div className="ui-error">{error}</div> : null}
      {success ? <div className="ui-success-msg">{success}</div> : null}

      <form onSubmit={onSubmit}>
        <div className="ui-field">
          <label htmlFor="forgot-email" className="ui-label ui-label-required">Email</label>
          <input id="forgot-email" className="ui-input" value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="email" required />
        </div>
        {human.widget}
        <button className="ui-btn-primary" style={{ width: '100%' }} disabled={loading}>
          {loading ? 'Sending...' : 'Send Reset Link'}
        </button>
      </form>

      {devToken ? (
        <div className="ui-card" style={{ marginTop: '1.2rem', padding: '0.9rem 1.1rem' }}>
          <strong style={{ fontSize: '0.85rem' }}>Development token:</strong>
          <div style={{ fontFamily: 'monospace', wordBreak: 'break-all', marginTop: '0.5rem', fontSize: '0.8rem', color: 'var(--ink-soft)' }}>
            {devToken}
          </div>
        </div>
      ) : null}
    </AuthLayout>
  );
}
