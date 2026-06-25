import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { auth } from '../../lib/auth';
import AuthLayout from '../../components/AuthLayout';

export default function Register() {
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [created, setCreated] = useState(null);

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
    setLoading(true);
    setError('');
    try {
      const data = await auth.register(name, email, password);
      setCreated(data?.user || null);
    } catch (err) {
      setError(err.message || 'Register failed');
    } finally {
      setLoading(false);
    }
  };

  if (created) {
    return (
      <AuthLayout
        title="Account created!"
        subtitle="Your account is ready. Use your email and password to log in."
        footer={<>Ready to go? <a href="/login">Log in</a></>}
      >
        <div className="ui-field">
          <label className="ui-label">Your username</label>
          <input className="ui-input" value={created.username || ''} readOnly />
          <p className="ui-muted" style={{ marginTop: '0.4rem', fontSize: '0.85rem' }}>
            This is your unique display name. You log in with your email.
          </p>
        </div>
        <button className="ui-btn-primary" style={{ width: '100%' }} onClick={() => navigate('/login', { replace: true })}>
          Continue to login
        </button>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Create an account"
      subtitle="Join us to adopt, foster, or support rescued Aspins."
      footer={<>Already have an account? <a href="/login">Log in</a></>}
    >
      {error ? <div className="ui-error">{error}</div> : null}
      <form onSubmit={onSubmit}>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Full name</label>
          <input className="ui-input" value={name} onChange={(e) => setName(e.target.value)} type="text" required />
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
          <input className="ui-input" value={email} onChange={(e) => setEmail(e.target.value)} type="email" required />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Password</label>
          <input className="ui-input" value={password} onChange={(e) => setPassword(e.target.value)} type="password" required minLength={8} />
        </div>
        <button className="ui-btn-primary" style={{ width: '100%' }} disabled={loading}>
          {loading ? 'Creating...' : 'Create Account'}
        </button>
      </form>
    </AuthLayout>
  );
}
