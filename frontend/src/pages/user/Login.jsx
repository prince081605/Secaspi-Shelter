import { useState } from 'react';
import { useNavigate, Link, useLocation } from 'react-router-dom';
import { auth } from '../../lib/auth';
import AuthLayout from '../../components/AuthLayout';
import PasswordInput from '../../components/PasswordInput';

export default function Login() {
  const navigate = useNavigate();
  const location = useLocation();

  // Where to go after logging in. Pages that gate an action — a visit request, a volunteer
  // application, a donation — send the route they were on, so the visitor resumes the form they
  // started rather than being dropped on the dashboard. Plain visits to /login have no state.
  const from = location.state?.from;

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const onSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      await auth.login(email, password);
      navigate(from || '/dashboard', { replace: true });
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
      footer={<>Don't have an account? <Link to="/register" state={{ from }}>Create one</Link></>}
    >
      {error ? <div className="ui-error">{error}</div> : null}
      <form onSubmit={onSubmit}>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Email</label>
          <input className="ui-input" value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="email" required />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Password</label>
          <PasswordInput
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            required
          />
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
