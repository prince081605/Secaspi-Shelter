import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { applyForFoster } from '../../lib/animalsApi';
import { todayLocal } from '../../lib/dates';
import SiteNav from '../../components/SiteNav';

const styles = `
  .applyBody { max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; }
  .applySuccess { padding: 2.5rem; text-align: center; }
  .applyRow { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  @media (max-width: 560px) {
    .applyRow { grid-template-columns: 1fr; }
    .applyBody { padding: 2rem 1rem; }
    .applySuccess { padding: 1.5rem 1.25rem; }
  }
`;

export default function FosterApply() {
  const { id } = useParams();
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

      <SiteNav back={{ to: `/adopt/${id}`, label: 'Back to Animal' }} />

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
                <label htmlFor="foster-full-name" className="ui-label ui-label-required">Full name</label>
                <input id="foster-full-name" className="ui-input" name="full_name" value={form.full_name} onChange={handleChange} required />
              </div>
              <div className="ui-field">
                <label htmlFor="foster-address" className="ui-label ui-label-required">Address</label>
                <input id="foster-address" className="ui-input" name="address" value={form.address} onChange={handleChange} required />
              </div>
              <div className="ui-field">
                <label htmlFor="foster-occupation" className="ui-label">Occupation</label>
                <input id="foster-occupation" className="ui-input" name="occupation" value={form.occupation} onChange={handleChange} />
              </div>
              <div className="ui-field">
                <label htmlFor="foster-housing-type" className="ui-label">Housing type</label>
                <input id="foster-housing-type" className="ui-input" name="housing_type" value={form.housing_type} onChange={handleChange} placeholder="e.g. Apartment, House with yard" />
              </div>
              <div className="ui-field">
                <label htmlFor="foster-pet-experience" className="ui-label">Pet experience</label>
                <textarea id="foster-pet-experience" className="ui-textarea" name="pet_experience" value={form.pet_experience} onChange={handleChange} />
              </div>
              <div className="ui-field">
                <label htmlFor="foster-why-do-you-want-to-foster" className="ui-label ui-label-required">Why do you want to foster?</label>
                <textarea id="foster-why-do-you-want-to-foster" className="ui-textarea" name="reason" value={form.reason} onChange={handleChange} required />
              </div>
              <div className="applyRow">
                <div className="ui-field">
                  <label htmlFor="foster-start-date" className="ui-label ui-label-required">Start date</label>
                  <input id="foster-start-date" className="ui-input" type="date" name="start_date" min={todayLocal()} value={form.start_date} onChange={handleChange} required />
                </div>
                <div className="ui-field">
                  <label htmlFor="foster-end-date" className="ui-label">End date</label>
                  <input id="foster-end-date" className="ui-input" type="date" name="end_date" min={form.start_date || todayLocal()} value={form.end_date} onChange={handleChange} />
                </div>
              </div>
              <div className="ui-field">
                <label htmlFor="foster-notes" className="ui-label">Notes</label>
                <textarea id="foster-notes" className="ui-textarea" name="notes" value={form.notes} onChange={handleChange} placeholder="Anything else we should know?" />
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
