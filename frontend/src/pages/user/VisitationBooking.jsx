import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { createVisitation, listVisitations } from '../../lib/visitationsApi';

const styles = `
  .visitBody { max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; }
  .visitSlots { display: flex; gap: 0.7rem; flex-wrap: wrap; margin-bottom: 0.4rem; }
  .visitNote { font-size: 0.85rem; color: var(--muted); margin-top: 0.3rem; }
  .visitList { list-style: none; padding: 0; margin: 1.2rem 0 0; }
  .visitItem { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 0.9rem 1.1rem; border: 1px solid var(--line); border-radius: 12px; margin-bottom: 0.7rem; }
  .visitItemMeta { font-size: 0.82rem; color: var(--muted); margin-top: 0.2rem; }
  .visitTag { padding: 0.3rem 0.7rem; border-radius: 999px; font-size: 0.78rem; font-weight: 600; text-transform: capitalize; white-space: nowrap; }
  .visitTag-pending { background: var(--brand-soft); color: var(--brand); }
  .visitTag-approved { background: #d8f3dc; color: #1b7a3d; }
  .visitTag-rejected { background: #ffe0e0; color: #b42318; }
  .visitTag-completed { background: var(--line); color: var(--ink-soft); }
  .visitSuccess { padding: 2.5rem; text-align: center; }
`;

const SLOTS = [
  { value: 'morning', label: 'Morning', hint: '9 AM – 12 PM' },
  { value: 'afternoon', label: 'Afternoon', hint: '1 PM – 4 PM' },
  { value: 'evening', label: 'Evening', hint: '4 PM – 6 PM' },
];

const SLOT_LABEL = Object.fromEntries(SLOTS.map((s) => [s.value, s.label]));

function toDateInput(d) {
  return d.toISOString().slice(0, 10);
}

export default function VisitationBooking() {
  const navigate = useNavigate();

  const now = new Date();
  const minDate = toDateInput(new Date(now.getTime() + 86400000)); // tomorrow
  const maxDate = toDateInput(new Date(now.getTime() + 30 * 86400000)); // +30 days

  const [requestedDate, setRequestedDate] = useState(minDate);
  const [timeSlot, setTimeSlot] = useState('morning');
  const [numVisitors, setNumVisitors] = useState(1);
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState(false);

  const [visits, setVisits] = useState([]);

  const loadVisits = () => {
    listVisitations()
      .then((res) => setVisits(res?.visitations || []))
      .catch(() => {});
  };

  useEffect(() => {
    loadVisits();
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');
    try {
      await createVisitation({
        requested_date: requestedDate,
        time_slot: timeSlot,
        num_visitors: Number(numVisitors) || 1,
        notes: notes || undefined,
      });
      setDone(true);
      setNotes('');
      loadVisits();
    } catch (err) {
      setError(err?.message || 'Failed to submit your visit request. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <nav className="ui-nav">
        <div className="ui-logo">SECASPI <span>Shelter</span></div>
        <button className="ui-btn-secondary" onClick={() => navigate('/')}>← Back to Home</button>
      </nav>

      <div className="visitBody">
        {done ? (
          <div className="ui-card visitSuccess">
            <h2 className="ui-h2" style={{ marginBottom: '0.6rem' }}>Visit request submitted!</h2>
            <p className="ui-muted">Our team will review your request and notify you once it's approved.</p>
            <div style={{ display: 'flex', gap: '0.7rem', justifyContent: 'center', marginTop: '1.5rem', flexWrap: 'wrap' }}>
              <button className="ui-btn-primary" onClick={() => setDone(false)}>Request another visit</button>
              <button className="ui-btn-secondary" onClick={() => navigate('/')}>Back to Home</button>
            </div>
          </div>
        ) : (
          <>
            <p className="ui-eyebrow" style={{ marginBottom: '1rem' }}>Come Meet Our Animals</p>
            <h1 className="ui-h1" style={{ marginBottom: '0.4rem' }}>Schedule a visit</h1>
            <p className="ui-muted" style={{ marginBottom: '2rem' }}>
              Pick a date and time, and our team will confirm your visit to the shelter.
            </p>

            {error && <div className="ui-error">{error}</div>}

            <form onSubmit={handleSubmit}>
              <div className="ui-field">
                <label className="ui-label ui-label-required">Preferred date</label>
                <input
                  className="ui-input"
                  type="date"
                  min={minDate}
                  max={maxDate}
                  value={requestedDate}
                  onChange={(e) => setRequestedDate(e.target.value)}
                  required
                />
                <div className="visitNote">Visits can be booked up to 30 days in advance.</div>
              </div>

              <div className="ui-field">
                <label className="ui-label ui-label-required">Time slot</label>
                <div className="visitSlots">
                  {SLOTS.map((s) => (
                    <button
                      type="button"
                      key={s.value}
                      className={timeSlot === s.value ? 'ui-btn-primary' : 'ui-btn-secondary'}
                      onClick={() => setTimeSlot(s.value)}
                    >
                      {s.label} <span style={{ opacity: 0.75, fontSize: '0.8em' }}>({s.hint})</span>
                    </button>
                  ))}
                </div>
              </div>

              <div className="ui-field">
                <label className="ui-label ui-label-required">Number of visitors</label>
                <input
                  className="ui-input"
                  type="number"
                  min="1"
                  max="20"
                  value={numVisitors}
                  onChange={(e) => setNumVisitors(e.target.value)}
                  required
                />
              </div>

              <div className="ui-field">
                <label className="ui-label">Anything we should know? (optional)</label>
                <textarea
                  className="ui-input"
                  rows={3}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="e.g., interested in a specific dog, bringing children, accessibility needs…"
                />
              </div>

              <button className="ui-btn-primary" style={{ width: '100%' }} type="submit" disabled={submitting}>
                {submitting ? 'Submitting…' : 'Request visit'}
              </button>
            </form>

            {visits.length > 0 && (
              <div style={{ marginTop: '2.5rem' }}>
                <h2 className="ui-h2" style={{ fontSize: '1.15rem', marginBottom: '0.8rem' }}>Your visit requests</h2>
                <ul className="visitList">
                  {visits.map((v) => (
                    <li className="visitItem" key={v.id}>
                      <div>
                        <div style={{ fontWeight: 600 }}>
                          {v.requested_date} · {SLOT_LABEL[v.time_slot] || v.time_slot}
                        </div>
                        <div className="visitItemMeta">
                          {v.num_visitors} visitor{v.num_visitors > 1 ? 's' : ''}
                          {v.admin_notes ? ` · Note: ${v.admin_notes}` : ''}
                        </div>
                      </div>
                      <span className={`visitTag visitTag-${v.status}`}>{v.status}</span>
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
