import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { applyForFoster } from '../../lib/animalsApi';

const styles = `
  .applyBody { max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; }
  .applySuccess { padding: 2.5rem; text-align: center; }
  .applyRow { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  @media (max-width: 560px) { .applyRow { grid-template-columns: 1fr; } }
`;

export default function FosterApply() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [form, setForm] = useState({
    full_name: '', address: '', occupation: '', housing_type: '', pet_experience: '', reason: '',
    start_date: '', end_date: '', notes: '',
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
      const data = await applyForFoster(id, form);
      setResult(data?.application || null);
    } catch (err) {
      setError(err?.message || 'Failed to submit foster application. Please try again.');
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
            <h2 className="ui-h2" style={{ marginBottom: '0.6rem' }}>Foster application submitted!</h2>
            <p className="ui-muted">We'll review your application and reach out to confirm the details.</p>
          </div>
        ) : (
          <>
            <h1 className="ui-h1" style={{ marginBottom: '0.4rem' }}>Foster Application</h1>
            <p className="ui-muted" style={{ marginBottom: '2rem' }}>Tell us about yourself and the dates you're available to foster.</p>

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
                <label className="ui-label ui-label-required">Why do you want to foster?</label>
                <textarea className="ui-textarea" name="reason" value={form.reason} onChange={handleChange} required />
              </div>
              <div className="applyRow">
                <div className="ui-field">
                  <label className="ui-label ui-label-required">Start date</label>
                  <input className="ui-input" type="date" name="start_date" value={form.start_date} onChange={handleChange} required />
                </div>
                <div className="ui-field">
                  <label className="ui-label">End date</label>
                  <input className="ui-input" type="date" name="end_date" value={form.end_date} onChange={handleChange} />
                </div>
              </div>
              <div className="ui-field">
                <label className="ui-label">Notes</label>
                <textarea className="ui-textarea" name="notes" value={form.notes} onChange={handleChange} placeholder="Anything else we should know?" />
              </div>
              <button className="ui-btn-primary" style={{ width: '100%' }} type="submit" disabled={submitting}>
                {submitting ? 'Submitting…' : 'Submit Foster Application'}
              </button>
            </form>
          </>
        )}
      </div>
    </div>
  );
}
