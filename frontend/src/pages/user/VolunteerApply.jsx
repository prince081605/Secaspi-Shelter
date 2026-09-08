import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { PawPrint, PartyPopper } from 'lucide-react';
import SiteNav from '../../components/SiteNav';
import useLoginGate from '../../lib/useLoginGate';
import { ID_TYPES } from '../../lib/validIdTypes';
import {
  submitVolunteerApplication,
  listMyVolunteerApplications,
  getMyVolunteer,
  requestVolunteerTask,
} from '../../lib/volunteersApi';

const styles = `
  .volBody { max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; }
  .volList { list-style: none; padding: 0; margin: 1.2rem 0 0; }
  .volItem { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 0.9rem 1.1rem; border: 1px solid var(--line); border-radius: 12px; margin-bottom: 0.7rem; }
  .volItemMeta { font-size: 0.82rem; color: var(--muted); margin-top: 0.2rem; }
  .volTag { padding: 0.3rem 0.7rem; border-radius: 999px; font-size: 0.78rem; font-weight: 600; text-transform: capitalize; white-space: nowrap; }
  .volTag-pending, .volTag-requested { background: var(--brand-soft); color: var(--brand); }
  .volTag-approved, .volTag-assigned, .volTag-ongoing { background: #d8f3dc; color: #1b7a3d; }
  .volTag-rejected { background: #ffe0e0; color: #b42318; }
  .volTag-completed { background: var(--line); color: var(--ink-soft); }
  .volSuccess { padding: 2.5rem; text-align: center; }
  .volIdPreview { display: block; max-width: min(320px, 100%); margin-top: 0.7rem; border: 1px solid var(--line); border-radius: 10px; }
  @media (max-width: 560px) {
    .volBody { padding: 2rem 1rem; }
    .volSuccess { padding: 1.5rem 1.25rem; }
    .volItem { flex-wrap: wrap; }
  }
`;

export default function VolunteerApply() {
  const navigate = useNavigate();
  const gate = useLoginGate('/volunteer');

  // Anonymous visitors have no volunteer record or applications to wait on, so the page starts
  // rendered rather than in its loading state.
  const [loading, setLoading] = useState(gate.isAuthed);
  const [volunteer, setVolunteer] = useState(null);
  const [applications, setApplications] = useState([]);

  // application form — restored from the draft when this page sent the visitor off to log in.
  // Strings only: the draft is JSON in sessionStorage (see useLoginGate), so anything here has
  // to survive JSON.stringify.
  const [form, setForm] = useState({
    availability: gate.draft?.availability || '',
    experience: gate.draft?.experience || '',
    reason: gate.draft?.reason || '',
    valid_id_type: gate.draft?.valid_id_type || '',
    valid_id_number: gate.draft?.valid_id_number || '',
  });
  // The ID photo is a File, which JSON.stringify turns into {} — so it is kept out of `form`
  // and its input only appears once signed in. Same trick, same reason, as the donation proof
  // screenshot on the Donate page. A visitor who gets bounced to the login page loses a file
  // they had already picked, which is why we never ask for one before they are signed in.
  const [idImage, setIdImage] = useState(null);
  const [idPreviewUrl, setIdPreviewUrl] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState(false);

  // task request
  const [taskName, setTaskName] = useState('');
  const [taskSubmitting, setTaskSubmitting] = useState(false);
  const [taskError, setTaskError] = useState('');

  const load = () => {
    setLoading(true);
    Promise.all([
      getMyVolunteer().catch(() => ({ volunteer: null })),
      listMyVolunteerApplications().catch(() => ({ applications: [] })),
    ])
      .then(([v, a]) => {
        setVolunteer(v?.volunteer || null);
        setApplications(a?.applications || []);
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    // Both endpoints are per-account; a visitor who is only reading the page gets the plain
    // application form, not two guaranteed 401s.
    if (gate.isAuthed) load();
  }, [gate.isAuthed]);

  const handleChange = (e) => setForm({ ...form, [e.target.name]: e.target.value });

  // Show the chosen ID before it is sent, so a blurry or wrong-side photo is caught here rather
  // than by a reviewer days later. The URL is minted in the handler and released by the effect
  // below — doing both in an effect would mean setting state from inside one.
  const chooseIdImage = (e) => {
    const file = e.target.files?.[0] || null;
    setIdImage(file);
    setIdPreviewUrl(file ? URL.createObjectURL(file) : '');
  };

  // Cleanup only: runs with the previous URL captured, so it releases the old preview when a
  // new file is picked and the last one when the page unmounts.
  useEffect(() => (
    () => { if (idPreviewUrl) URL.revokeObjectURL(idPreviewUrl); }
  ), [idPreviewUrl]);

  const handleSubmit = async (e) => {
    e.preventDefault();

    // The application is reviewed and approved against an account, so submitting is where the
    // login is required — reading the page and writing the answers is not.
    if (!gate.isAuthed) {
      gate.askToLogin(form);
      return;
    }

    // The file input is only rendered once signed in, so `required` on it cannot cover the
    // case where someone signs in and submits without picking one.
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
      body.append('availability', form.availability);
      body.append('experience', form.experience);
      body.append('reason', form.reason);
      body.append('valid_id_type', form.valid_id_type);
      body.append('valid_id_number', form.valid_id_number);
      body.append('valid_id_image', idImage);
      await submitVolunteerApplication(body);
      setDone(true);
      load();
    } catch (err) {
      if (gate.handleAuthError(err, form)) return;
      // Laravel's 422 puts the useful sentence in `errors`, not `message` — without this an
      // oversized ID photo would report only "Validation failed".
      const fieldError = Object.values(err?.data?.errors || {})[0]?.[0];
      setError(fieldError || err?.message || 'Failed to submit your application. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleRequestTask = async (e) => {
    e.preventDefault();
    setTaskSubmitting(true);
    setTaskError('');
    try {
      await requestVolunteerTask({ task_name: taskName });
      setTaskName('');
      load();
    } catch (err) {
      setTaskError(err?.message || 'Failed to request task. Please try again.');
    } finally {
      setTaskSubmitting(false);
    }
  };

  const hasPending = applications.some((a) => a.status === 'pending');

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <SiteNav />

      <div className="volBody">
        {loading ? (
          <div className="ui-empty">Loading…</div>
        ) : volunteer ? (
          /* ---- Approved volunteer/staff: task dashboard ---- */
          <>
            <p className="ui-eyebrow" style={{ marginBottom: '1rem' }}>{volunteer.type === 'staff' ? 'Staff Dashboard' : 'Volunteer Dashboard'}</p>
            <h1 className="ui-h1" style={{ marginBottom: '0.4rem' }}>Welcome back, {volunteer.type === 'staff' ? 'staff member' : 'volunteer'}! <PawPrint size={22} style={{ verticalAlign: '-3px' }} /></h1>
            <p className="ui-muted" style={{ marginBottom: '2rem' }}>
              Request a task you'd like to help with — the team will confirm it.
            </p>

            {taskError && <div className="ui-error">{taskError}</div>}

            <form onSubmit={handleRequestTask} style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
              <div className="ui-field" style={{ flex: 1, marginBottom: 0 }}>
                <label className="ui-label ui-label-required">Task you'd like to do</label>
                <input
                  className="ui-input"
                  value={taskName}
                  onChange={(e) => setTaskName(e.target.value)}
                  placeholder="e.g. Walk the dogs on Saturday"
                  required
                />
              </div>
              <button className="ui-btn-primary" type="submit" disabled={taskSubmitting || !taskName.trim()}>
                {taskSubmitting ? 'Sending…' : 'Request task'}
              </button>
            </form>

            <div style={{ marginTop: '2rem' }}>
              <h2 className="ui-h2" style={{ fontSize: '1.15rem', marginBottom: '0.8rem' }}>My tasks</h2>
              {(!volunteer.tasks || volunteer.tasks.length === 0) ? (
                <p className="ui-muted">No tasks yet. Request one above to get started!</p>
              ) : (
                <ul className="volList">
                  {volunteer.tasks.map((t) => (
                    <li className="volItem" key={t.id}>
                      <div>
                        <div style={{ fontWeight: 600 }}>{t.task_name}</div>
                        <div className="volItemMeta">
                          {t.status === 'requested' ? 'Awaiting confirmation' : t.assigned_date || ''}
                        </div>
                      </div>
                      <span className={`volTag volTag-${t.status}`}>{t.status}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </>
        ) : done || hasPending ? (
          /* ---- Application submitted / pending ---- */
          <div className="ui-card volSuccess">
            <h2 className="ui-h2" style={{ marginBottom: '0.6rem' }}>Application received! <PartyPopper size={20} style={{ verticalAlign: '-3px' }} /></h2>
            <p className="ui-muted">
              Thanks for offering to help. Our team will review your application and notify you once it's approved.
            </p>
            <div style={{ display: 'flex', gap: '0.7rem', justifyContent: 'center', marginTop: '1.5rem', flexWrap: 'wrap' }}>
              <button className="ui-btn-secondary" onClick={() => navigate('/')}>Back to Home</button>
            </div>
          </div>
        ) : (
          /* ---- Application form ---- */
          <>
            <p className="ui-eyebrow" style={{ marginBottom: '1rem' }}>Join the Team</p>
            <h1 className="ui-h1" style={{ marginBottom: '0.4rem' }}>Become a volunteer</h1>
            <p className="ui-muted" style={{ marginBottom: '2rem' }}>
              Tell us a little about yourself and how you'd like to help our rescues.
            </p>

            {!gate.isAuthed && (
              <div className="ui-notice">
                Tell us about yourself now — you'll just need to{' '}
                <Link to="/login" state={{ from: '/volunteer' }}>log in</Link> to send the
                application, and we'll bring you straight back with your answers kept.
              </div>
            )}

            {error && <div className="ui-error">{error}</div>}

            <form onSubmit={handleSubmit}>
              <div className="ui-field">
                <label className="ui-label ui-label-required">Availability</label>
                <input
                  className="ui-input"
                  name="availability"
                  value={form.availability}
                  onChange={handleChange}
                  placeholder="e.g. Weekends, weekday evenings"
                  required
                />
              </div>
              <div className="ui-field">
                <label className="ui-label">Prior experience (optional)</label>
                <textarea
                  className="ui-textarea"
                  name="experience"
                  value={form.experience}
                  onChange={handleChange}
                  placeholder="Any experience with animals, events, etc."
                />
              </div>
              <div className="ui-field">
                <label className="ui-label ui-label-required">Why do you want to volunteer?</label>
                <textarea
                  className="ui-textarea"
                  name="reason"
                  value={form.reason}
                  onChange={handleChange}
                  required
                />
              </div>
              <div className="ui-field">
                <label className="ui-label ui-label-required">Type of ID</label>
                <select
                  className="ui-input"
                  name="valid_id_type"
                  value={form.valid_id_type}
                  onChange={handleChange}
                  required
                >
                  <option value="">Select an ID</option>
                  {ID_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
              </div>
              <div className="ui-field">
                <label className="ui-label ui-label-required">ID number</label>
                <input
                  className="ui-input"
                  name="valid_id_number"
                  value={form.valid_id_number}
                  onChange={handleChange}
                  maxLength={100}
                  required
                />
              </div>
              {/* Rendered only once signed in — and conditionally, not merely disabled. A
                  `required` file input on the anonymous form would make the browser block
                  submission before handleSubmit ran, putting the login gate out of reach. */}
              {gate.isAuthed ? (
                <div className="ui-field">
                  <label className="ui-label ui-label-required">Photo of your ID</label>
                  <input
                    className="ui-input"
                    type="file"
                    accept="image/*"
                    onChange={chooseIdImage}
                    required
                  />
                  <p className="ui-muted" style={{ fontSize: '0.82rem', marginTop: '0.4rem' }}>
                    A clear photo of the ID above, so we can confirm it is yours. JPG or PNG, up to 5 MB.
                  </p>
                  {idPreviewUrl && <img src={idPreviewUrl} alt="Your ID, as it will be submitted" className="volIdPreview" />}
                </div>
              ) : (
                <p className="ui-muted" style={{ fontSize: '0.85rem' }}>
                  You'll attach a photo of this ID after logging in.
                </p>
              )}
              <button className="ui-btn-primary" style={{ width: '100%' }} type="submit" disabled={submitting}>
                {submitting ? 'Submitting…' : gate.isAuthed ? 'Submit application' : 'Log in to submit application'}
              </button>
            </form>

            {applications.length > 0 && (
              <div style={{ marginTop: '2.5rem' }}>
                <h2 className="ui-h2" style={{ fontSize: '1.15rem', marginBottom: '0.8rem' }}>My applications</h2>
                <ul className="volList">
                  {applications.map((a) => (
                    <li className="volItem" key={a.id}>
                      <div>
                        <div style={{ fontWeight: 600 }}>{a.availability || 'Volunteer application'}</div>
                        <div className="volItemMeta">{a.admin_notes ? `Note: ${a.admin_notes}` : ''}</div>
                      </div>
                      <span className={`volTag volTag-${a.status}`}>{a.status}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
