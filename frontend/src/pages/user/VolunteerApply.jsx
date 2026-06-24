import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
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
`;

export default function VolunteerApply() {
  const navigate = useNavigate();

  const [loading, setLoading] = useState(true);
  const [volunteer, setVolunteer] = useState(null);
  const [applications, setApplications] = useState([]);

  // application form
  const [form, setForm] = useState({ availability: '', experience: '', reason: '' });
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
    load();
  }, []);

  const handleChange = (e) => setForm({ ...form, [e.target.name]: e.target.value });

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');
    try {
      await submitVolunteerApplication(form);
      setDone(true);
      load();
    } catch (err) {
      setError(err?.message || 'Failed to submit your application. Please try again.');
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

      <nav className="ui-nav">
        <div className="ui-logo">SECASPI <span>Shelter</span></div>
        <button className="ui-btn-secondary" onClick={() => navigate('/')}>← Back to Home</button>
      </nav>

      <div className="volBody">
        {loading ? (
          <div className="ui-empty">Loading…</div>
        ) : volunteer ? (
          /* ---- Approved volunteer/staff: task dashboard ---- */
          <>
            <p className="ui-eyebrow" style={{ marginBottom: '1rem' }}>{volunteer.type === 'staff' ? 'Staff Dashboard' : 'Volunteer Dashboard'}</p>
            <h1 className="ui-h1" style={{ marginBottom: '0.4rem' }}>Welcome back, {volunteer.type === 'staff' ? 'staff member' : 'volunteer'}! 🐾</h1>
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
            <h2 className="ui-h2" style={{ marginBottom: '0.6rem' }}>Application received! 🙌</h2>
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
              <button className="ui-btn-primary" style={{ width: '100%' }} type="submit" disabled={submitting}>
                {submitting ? 'Submitting…' : 'Submit application'}
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
