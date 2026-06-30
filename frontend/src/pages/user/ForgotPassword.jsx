import { useState } from 'react';
import { Link } from 'react-router-dom';
import { auth } from '../../lib/auth';
import AuthLayout from '../../components/AuthLayout';

export default function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [devToken, setDevToken] = useState('');

  const onSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    setSuccess('');
    setDevToken('');

    try {
      const res = await auth.forgotPassword(email);
      setSuccess(res.message || 'Request submitted');
      if (res.token) setDevToken(res.token);
    } catch (err) {
      setError(err.message || 'Request failed');
    } finally {
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
          <label className="ui-label ui-label-required">Email</label>
          <input className="ui-input" value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="email" required />
        </div>
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
