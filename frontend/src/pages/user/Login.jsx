import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { auth } from '../../lib/auth';
import AuthLayout from '../../components/AuthLayout';

export default function Login() {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const onSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      await auth.login(email, password);
      navigate('/dashboard', { replace: true });
    } catch (err) {
      setError(err.message || 'Login failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthLayout
      title="Welcome back"
      subtitle="Log in to manage your adoptions and donations."
      footer={<>Don't have an account? <Link to="/register">Create one</Link></>}
    >
      {error ? <div className="ui-error">{error}</div> : null}
      <form onSubmit={onSubmit}>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Email</label>
          <input className="ui-input" value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="email" required />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Password</label>
          <div style={{ position: 'relative' }}>
            <input
              className="ui-input"
              style={{ paddingRight: '2.8rem' }}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              type={showPassword ? 'text' : 'password'}
              autoComplete="current-password"
              required
            />
            <button
              type="button"
              onClick={() => setShowPassword((v) => !v)}
              aria-label={showPassword ? 'Hide password' : 'Show password'}
              style={{
                position: 'absolute', right: '0.9rem', top: '50%', transform: 'translateY(-50%)',
                background: 'none', border: 'none', cursor: 'pointer', padding: 0,
                fontSize: '1.1rem', lineHeight: 1, color: 'var(--muted)',
              }}
            >
              {showPassword ? '🙈' : '👁️'}
            </button>
          </div>
        </div>
        <button className="ui-btn-primary" style={{ width: '100%' }} disabled={loading}>
          {loading ? 'Logging in...' : 'Log In'}
        </button>
        <p style={{ marginTop: '1rem', textAlign: 'center', fontSize: '0.9rem' }}>
          <Link to="/forgot-password" style={{ color: 'var(--muted)' }}>Forgot password?</Link>
        </p>
      </form>
    </AuthLayout>
  );
}
