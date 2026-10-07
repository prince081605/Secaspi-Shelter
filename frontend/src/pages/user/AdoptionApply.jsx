import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { applyForAdoption } from '../../lib/animalsApi';
import { ID_TYPES } from '../../lib/validIdTypes';
import SiteNav from '../../components/SiteNav';
import PhotoInput from '../../components/PhotoInput';

const styles = `
  .applyBody { max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; }
  .applySuccess { padding: 2.5rem; text-align: center; }
  .applyIdPreview { display: block; max-width: min(320px, 100%); margin-top: 0.7rem; border: 1px solid var(--line); border-radius: 10px; }
  @media (max-width: 560px) {
    .applyBody { padding: 2rem 1rem; }
    .applySuccess { padding: 1.5rem 1.25rem; }
  }
`;

export default function AdoptionApply() {
  const { id } = useParams();
  const [form, setForm] = useState({
    full_name: '', contact_number: '', address: '', occupation: '', housing_type: '', pet_experience: '', reason: '',
    valid_id_type: '',
  });
  // The ID photo is a File, so it lives outside `form` (which is plain strings).
  const [idImage, setIdImage] = useState(null);
  const [idPreviewUrl, setIdPreviewUrl] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState(null);

  const handleChange = (e) => setForm({ ...form, [e.target.name]: e.target.value });

  // Show the chosen ID before it is sent, so a blurry or wrong-side photo is caught here rather
  // than by a reviewer later. Minted in the handler, released by the effect below.
  const chooseIdImage = ([file = null]) => {
    setIdImage(file);
    setIdPreviewUrl(file ? URL.createObjectURL(file) : '');
  };

  useEffect(() => (
    () => { if (idPreviewUrl) URL.revokeObjectURL(idPreviewUrl); }
  ), [idPreviewUrl]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!idImage) {
      setError('Please attach a photo of your ID.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      // FormData rather than JSON now that a file rides along; lib/api.js drops the
      // Content-Type header for FormData so the browser can set the multipart boundary.
      const body = new FormData();
      Object.entries(form).forEach(([key, value]) => body.append(key, value));
      body.append('valid_id_image', idImage);
      const data = await applyForAdoption(id, body);
      setResult(data?.application || null);
    } catch (err) {
      // Laravel's 422 puts the useful sentence in `errors`, not `message`.
      const fieldError = Object.values(err?.data?.errors || {})[0]?.[0];
      setError(fieldError || err?.message || 'Failed to submit application. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <SiteNav />

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
                <label className="ui-label ui-label-required">Contact number</label>
                <input className="ui-input" name="contact_number" type="tel" value={form.contact_number} onChange={handleChange} placeholder="e.g. 09XX XXX XXXX" required />
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
              <div className="ui-field">
                <label className="ui-label ui-label-required">Type of ID</label>
                <select className="ui-input" name="valid_id_type" value={form.valid_id_type} onChange={handleChange} required>
                  <option value="">Select an ID</option>
                  {ID_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
              </div>
              <div className="ui-field">
                <label className="ui-label ui-label-required">Photo of your ID</label>
                <PhotoInput files={idImage ? [idImage] : null} onChange={chooseIdImage} required thumbnails={false} label="Photo of your ID" cameraTitle="Photograph your ID" />
                <p className="ui-muted" style={{ fontSize: '0.82rem', marginTop: '0.4rem' }}>
                  A clear photo of the ID above, so we can confirm it is yours. JPG or PNG, up to 5 MB.
                </p>
                {idPreviewUrl && <img src={idPreviewUrl} alt="Your ID, as it will be submitted" className="applyIdPreview" />}
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
