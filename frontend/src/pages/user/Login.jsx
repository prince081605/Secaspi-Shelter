import { useState } from 'react';
import { useNavigate, Link, useLocation } from 'react-router-dom';
import { auth } from '../../lib/auth';
import AuthLayout from '../../components/AuthLayout';
import PasswordInput from '../../components/PasswordInput';
import useHumanCheck from '../../lib/useHumanCheck';

export default function Login() {
  const navigate = useNavigate();
  const location = useLocation();

  // Where to go after logging in. Pages that gate an action — a visit request, a volunteer
  // application, a donation — send the route they were on, so the visitor resumes the form they
  // started rather than being dropped on the dashboard. Plain visits to /login have no state.
  const from = location.state?.from;

  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const human = useHumanCheck();

  const onSubmit = async (e) => {
    e.preventDefault();
    if (!human.ready) {
      setError(human.notReadyMessage);
      return;
    }
    setLoading(true);
    setError('');
    try {
      await auth.login(login, password, human.fields);
      navigate(from || '/dashboard', { replace: true });
    } catch (err) {
      setError(err.message || 'Login failed');
      human.reset();
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
          <label htmlFor="login-email" className="ui-label ui-label-required">Email or username</label>
          <input id="login-email" className="ui-input" value={login} onChange={(e) => setLogin(e.target.value)} type="text" autoComplete="username" autoCapitalize="none" spellCheck={false} placeholder="you@example.com or your username" required />
        </div>
        <div className="ui-field">
          <label htmlFor="login-password" className="ui-label ui-label-required">Password</label>
          <PasswordInput id="login-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            required
          />
        </div>
        {human.widget}
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
