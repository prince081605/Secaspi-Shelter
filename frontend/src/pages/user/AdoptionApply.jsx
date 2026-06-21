import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { applyForAdoption } from '../../lib/animalsApi';

const styles = `
  .applyBody { max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; }
  .applySuccess { padding: 2.5rem; text-align: center; }
`;

export default function AdoptionApply() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [form, setForm] = useState({
    full_name: '', address: '', occupation: '', housing_type: '', pet_experience: '', reason: '',
  });
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState(null);

  const handleChange = (e) => setForm({ ...form, [e.target.name]: e.target.value });

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');
    try {
      const data = await applyForAdoption(id, form);
      setResult(data?.application || null);
    } catch (err) {
      setError(err?.message || 'Failed to submit application. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <nav className="ui-nav">
        <div className="ui-logo">SECASPI <span>Shelter</span></div>
        <button className="ui-btn-secondary" onClick={() => navigate(`/adopt/${id}`)}>← Back to Animal</button>
      </nav>

      <div className="applyBody">
        {result ? (
          <div className="ui-card applySuccess">
            <h2 className="ui-h2" style={{ marginBottom: '0.6rem' }}>Application submitted!</h2>
            <p className="ui-muted">We'll review your application and get back to you soon.</p>
            <p style={{ marginTop: '0.8rem' }}>Reference number: <strong style={{ color: 'var(--brand)' }}>{result.reference_no}</strong></p>
          </div>
        ) : (
          <>
            <h1 className="ui-h1" style={{ marginBottom: '0.4rem' }}>Adoption Application</h1>
            <p className="ui-muted" style={{ marginBottom: '2rem' }}>Tell us a bit about yourself so we can find the right fit.</p>

            {error && <div className="ui-error">{error}</div>}

            <form onSubmit={handleSubmit}>
              <div className="ui-field">
                <label className="ui-label ui-label-required">Full name</label>
                <input className="ui-input" name="full_name" value={form.full_name} onChange={handleChange} required />
              </div>
              <div className="ui-field">
                <label className="ui-label ui-label-required">Address</label>
                <input className="ui-input" name="address" value={form.address} onChange={handleChange} required />
              </div>
              <div className="ui-field">
                <label className="ui-label">Occupation</label>
                <input className="ui-input" name="occupation" value={form.occupation} onChange={handleChange} />
              </div>
              <div className="ui-field">
                <label className="ui-label">Housing type</label>
                <input className="ui-input" name="housing_type" value={form.housing_type} onChange={handleChange} placeholder="e.g. Apartment, House with yard" />
              </div>
              <div className="ui-field">
                <label className="ui-label">Pet experience</label>
                <textarea className="ui-textarea" name="pet_experience" value={form.pet_experience} onChange={handleChange} />
              </div>
              <div className="ui-field">
                <label className="ui-label ui-label-required">Why do you want to adopt?</label>
                <textarea className="ui-textarea" name="reason" value={form.reason} onChange={handleChange} required />
              </div>
              <button className="ui-btn-primary" style={{ width: '100%' }} type="submit" disabled={submitting}>
                {submitting ? 'Submitting…' : 'Submit Application'}
              </button>
            </form>
          </>
        )}
      </div>
    </div>
  );
}
