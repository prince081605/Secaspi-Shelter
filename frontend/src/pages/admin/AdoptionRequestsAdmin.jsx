import { useEffect, useState } from 'react';
import { adminListAdoptionApplications, adminMarkAdoptionApplicationRead, adminUpdateAdoptionApplication } from '../../lib/animalsApi';
import StatusBadge from '../../components/StatusBadge';

const STATUSES = ['pending', 'approved', 'declined', 'completed'];
const HOME_VISIT_STATUSES = ['not_scheduled', 'scheduled', 'completed'];

function photoSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

function HomeVisitPanel({ application, onSaved }) {
  const [status, setStatus] = useState(application.home_visit_status || 'not_scheduled');
  const [date, setDate] = useState(application.home_visit_date || '');
  const [notes, setNotes] = useState(application.home_visit_notes || '');
  const [state, setState] = useState({ status: 'idle', error: '' });

  const handleSave = async () => {
    setState({ status: 'loading', error: '' });
    try {
      await adminUpdateAdoptionApplication(application.id, {
        home_visit_status: status,
        home_visit_date: date || null,
        home_visit_notes: notes || null,
      });
      setState({ status: 'idle', error: '' });
      onSaved();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to save home visit.' });
    }
  };

  return (
    <div className="dashFormGrid" style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      <div className="ui-field">
        <label className="ui-label">Home visit status</label>
        <select className="ui-input" value={status} onChange={(e) => setStatus(e.target.value)}>
          {HOME_VISIT_STATUSES.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
        </select>
      </div>
      <div className="ui-field">
        <label className="ui-label">Visit date</label>
        <input className="ui-input" type="date" value={date || ''} onChange={(e) => setDate(e.target.value)} />
      </div>
      <div className="ui-field" style={{ gridColumn: '1 / -1' }}>
        <label className="ui-label">Notes</label>
        <textarea className="ui-input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </div>
      <div>
        <button className="dashBtn dashBtnPrimary" type="button" onClick={handleSave} disabled={state.status === 'loading'}>
          {state.status === 'loading' ? 'Saving…' : 'Save home visit'}
        </button>
      </div>
    </div>
  );
}

function ApplicationRow({ application, onChanged, onUnreadChanged }) {
  const [expanded, setExpanded] = useState(false);
  const [error, setError] = useState('');
  const isUnread = !application.read_at;

  const handleInteracted = () => {
    onChanged();
    onUnreadChanged?.();
  };

  const setStatus = async (status) => {
    setError('');
    try {
      await adminUpdateAdoptionApplication(application.id, { status });
      handleInteracted();
    } catch (err) {
      setError(err?.message || 'Failed to update status.');
    }
  };

  const toggleExpanded = async () => {
    const next = !expanded;
    setExpanded(next);
    if (next && isUnread) {
      try {
        await adminMarkAdoptionApplicationRead(application.id);
        handleInteracted();
      } catch {
        // non-critical: the unread highlight just won't clear until the next interaction
      }
    }
  };

  return (
    <>
      <tr className={isUnread ? 'dashRowUnread' : ''}>
        <td>{application.reference_no}</td>
        <td className="dashFlexRow">
          {application.animal?.photo ? (
            <img src={photoSrc(application.animal.photo)} alt="" className="dashThumbSm" />
          ) : null}
          {application.animal?.name || 'Unknown'}
        </td>
        <td>{application.applicant?.full_name || application.full_name}</td>
        <td><StatusBadge status={application.status} /></td>
        <td><StatusBadge status={application.home_visit_status} /></td>
        <td>{(application.created_at || '').slice(0, 10)}</td>
        <td style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {application.status !== 'approved' && (
            <button className="dashBtn dashBtnPrimary" onClick={() => setStatus('approved')}>Approve</button>
          )}
          {application.status !== 'declined' && (
            <button className="dashBtn dashBtnDanger" onClick={() => setStatus('declined')}>Decline</button>
          )}
          {application.status === 'approved' && (
            <button className="dashBtn" onClick={() => setStatus('completed')}>Mark completed</button>
          )}
          <button className="dashBtn" onClick={toggleExpanded}>{expanded ? 'Hide' : 'Details'}</button>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={7} className="dashExpandPanel">
            {error && <div className="ui-error">{error}</div>}
            <div className="dashFormGrid">
              <div><strong>Address:</strong> {application.address || '—'}</div>
              <div><strong>Occupation:</strong> {application.occupation || '—'}</div>
              <div><strong>Housing type:</strong> {application.housing_type || '—'}</div>
              <div><strong>Email:</strong> {application.applicant?.email || '—'}</div>
              <div><strong>Phone:</strong> {application.applicant?.phone || '—'}</div>
            </div>
            <div style={{ marginTop: 8 }}><strong>Pet experience:</strong> {application.pet_experience || '—'}</div>
            <div style={{ marginTop: 4 }}><strong>Reason:</strong> {application.reason || '—'}</div>
            <div className="dashSectionTitle" style={{ marginTop: 14, fontSize: 14 }}>🏠 Home visit tracking</div>
            <HomeVisitPanel application={application} onSaved={handleInteracted} />
          </td>
        </tr>
      )}
    </>
  );
}

export default function AdoptionRequestsAdmin({ onUnreadChanged }) {
  const [applications, setApplications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [status, setStatusFilter] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListAdoptionApplications({ status })
      .then((data) => {
        if (!mounted) return;
        setApplications(data?.data || []);
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load adoption requests.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [status, refreshKey]);

  const refresh = () => setRefreshKey((k) => k + 1);

  return (
    <>
      <div className="dashSectionTitle">📩 Adoption Requests</div>
      {error && <div className="ui-error">{error}</div>}

      <div style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
        <select className="ui-input" style={{ maxWidth: 180 }} aria-label="Filter adoption requests by status" value={status} onChange={(e) => setStatusFilter(e.target.value)}>
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : applications.length === 0 ? (
        <div className="ui-empty">No adoption applications match this filter.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>Reference</th>
                <th>Animal</th>
                <th>Applicant</th>
                <th>Status</th>
                <th>Home visit</th>
                <th>Submitted</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {applications.map((a) => (
                <ApplicationRow key={a.id} application={a} onChanged={refresh} onUnreadChanged={onUnreadChanged} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
