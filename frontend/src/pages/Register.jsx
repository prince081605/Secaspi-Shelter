import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { auth } from '../lib/auth';
import AuthLayout from '../components/AuthLayout';

export default function Register() {
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const onSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      await auth.register(name, email, password);
      navigate('/login', { replace: true });
    } catch (err) {
      setError(err.message || 'Register failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthLayout
      title="Create an account"
      subtitle="Join us to adopt, foster, or support rescued Aspins."
      footer={<>Already have an account? <a href="/login">Log in</a></>}
    >
      {error ? <div className="ui-error">{error}</div> : null}
      <form onSubmit={onSubmit}>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Name</label>
          <input className="ui-input" value={name} onChange={(e) => setName(e.target.value)} type="text" required />
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
