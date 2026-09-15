import { useEffect, useState } from 'react';
import { useNavigate, Link, useLocation } from 'react-router-dom';
import { auth } from '../../lib/auth';
import AuthLayout from '../../components/AuthLayout';
import PasswordInput from '../../components/PasswordInput';

export default function Register() {
  const navigate = useNavigate();
  const location = useLocation();

  // Someone gated out of a form may register rather than log in; carry the page they were headed
  // for through to the login step so the detour still ends where it started.
  const from = location.state?.from;

  const [name, setName] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [created, setCreated] = useState(null);

  // Resend-verification state for the success card.
  const [resending, setResending] = useState(false);
  const [resendMsg, setResendMsg] = useState('');

  // Live preview of the system-assigned username as they type their name (debounced).
  // The username is read-only — the server assigns the final, guaranteed-unique value.
  useEffect(() => {
    const t = setTimeout(() => {
      if (name.trim() === '') {
        setUsername('');
      } else {
        auth.suggestUsername(name).then(setUsername).catch(() => {});
      }
    }, 400);
    return () => clearTimeout(t);
  }, [name]);

  const onSubmit = async (e) => {
    e.preventDefault();
    setError('');

    // Catch the mismatch on the client so the user isn't bounced off the server for it — the
    // server enforces the same rule (`confirmed`) as the real guard.
    if (password !== confirmPassword) {
      setError('Passwords do not match.');
      return;
    }

    setLoading(true);
    try {
      const data = await auth.register(name, email, password, confirmPassword);
      setCreated(data?.user || null);
    } catch (err) {
      setError(err.message || 'Register failed');
    } finally {
      setLoading(false);
    }
  };

  const onResend = async () => {
    setResending(true);
    setResendMsg('');
    try {
      await auth.resendVerification(created?.email || email);
      setResendMsg('Verification email sent. Check your inbox (and spam).');
    } catch (err) {
      setResendMsg(err?.message || 'Could not resend right now. Try again shortly.');
    } finally {
      setResending(false);
    }
  };

  if (created) {
    return (
      <AuthLayout
        title="Almost there — verify your email"
        subtitle={`We sent a verification link to ${created.email || email}. Open it to confirm your address.`}
        footer={<>Already verified? <Link to="/login" state={{ from }}>Log in</Link></>}
      >
        <div className="ui-field">
          <label className="ui-label">Your username</label>
          <input className="ui-input" value={created.username || ''} readOnly />
          <p className="ui-muted" style={{ marginTop: '0.4rem', fontSize: '0.85rem' }}>
            This is your unique display name. You log in with your email.
          </p>
        </div>
        {resendMsg ? <div className="ui-success-msg" style={{ marginBottom: '0.75rem' }}>{resendMsg}</div> : null}
        <p className="ui-muted" style={{ marginBottom: '0.75rem', fontSize: '0.85rem' }}>
          Didn&apos;t get the email? Check your spam folder, or resend it.
        </p>
        <button
          type="button"
          className="ui-btn-secondary"
          style={{ width: '100%', marginBottom: '0.6rem' }}
          onClick={onResend}
          disabled={resending}
        >
          {resending ? 'Sending…' : 'Resend verification email'}
        </button>
        <button className="ui-btn-primary" style={{ width: '100%' }} onClick={() => navigate('/login', { replace: true, state: { from } })}>
          Continue to login
        </button>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Create an account"
      subtitle="Join us to adopt, foster, or support rescued Aspins."
      footer={<>Already have an account? <Link to="/login" state={{ from }}>Log in</Link></>}
    >
      {error ? <div className="ui-error">{error}</div> : null}
      <form onSubmit={onSubmit}>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Full name</label>
          <input className="ui-input" value={name} onChange={(e) => setName(e.target.value)} type="text" autoComplete="name" required />
        </div>
        <div className="ui-field">
          <label className="ui-label">Username (auto-generated)</label>
          <input className="ui-input" value={username} placeholder="Filled in from your name" readOnly />
          <p className="ui-muted" style={{ marginTop: '0.4rem', fontSize: '0.85rem' }}>
            We create a unique username for you from your name.
          </p>
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Email</label>
          <input className="ui-input" value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="email" required />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Password</label>
          <PasswordInput
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="new-password"
            required
            minLength={8}
          />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Confirm password</label>
          <PasswordInput
            value={confirmPassword}
            onChange={(e) => setConfirmPassword(e.target.value)}
            autoComplete="new-password"
            required
            minLength={8}
          />
          {confirmPassword && password !== confirmPassword ? (
            <p className="ui-muted" style={{ marginTop: '0.4rem', fontSize: '0.85rem', color: 'var(--danger, #c0392b)' }}>
              Passwords do not match.
            </p>
          ) : null}
        </div>
        <button className="ui-btn-primary" style={{ width: '100%' }} disabled={loading}>
          {loading ? 'Creating...' : 'Create Account'}
        </button>
      </form>
    </AuthLayout>
  );
}
