import { useEffect, useState } from 'react';
import { adminListFosterApplications, adminUpdateFosterApplication } from '../../lib/animalsApi';
import { Home } from 'lucide-react';
import StatusBadge from '../../components/StatusBadge';
import Pagination from '../../components/Pagination';

const STATUSES = ['pending', 'approved', 'active', 'completed', 'declined'];

function photoSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

function MonitoringPanel({ application, onSaved }) {
  const [startDate, setStartDate] = useState(application.start_date || '');
  const [endDate, setEndDate] = useState(application.end_date || '');
  const [notes, setNotes] = useState(application.notes || '');
  const [state, setState] = useState({ status: 'idle', error: '' });

  const handleSave = async () => {
    setState({ status: 'loading', error: '' });
    try {
      await adminUpdateFosterApplication(application.id, {
        start_date: startDate || undefined,
        end_date: endDate || '',
        notes,
      });
      setState({ status: 'idle', error: '' });
      onSaved();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to save.' });
    }
  };

  return (
    <div className="dashFormGrid" style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      <div className="ui-field">
        <label className="ui-label">Start date</label>
        <input className="ui-input" type="date" value={startDate || ''} onChange={(e) => setStartDate(e.target.value)} />
      </div>
      <div className="ui-field">
        <label className="ui-label">End date</label>
        <input className="ui-input" type="date" value={endDate || ''} onChange={(e) => setEndDate(e.target.value)} />
      </div>
      <div className="ui-field" style={{ gridColumn: '1 / -1' }}>
        <label className="ui-label">Monitoring notes</label>
        <textarea className="ui-input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </div>
      <div>
        <button className="dashBtn dashBtnPrimary" type="button" onClick={handleSave} disabled={state.status === 'loading'}>
          {state.status === 'loading' ? 'Saving…' : 'Save'}
        </button>
      </div>
    </div>
  );
}

function ApplicationRow({ application, onChanged }) {
  const [expanded, setExpanded] = useState(false);
  const [error, setError] = useState('');

  const setStatus = async (status) => {
    setError('');
    try {
      await adminUpdateFosterApplication(application.id, { status });
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to update status.');
    }
  };

  return (
    <>
      <tr>
        <td>
          <div className="dashFlexRow">
            {application.animal?.photo ? (
              <img src={photoSrc(application.animal.photo)} alt="" className="dashThumbSm" />
            ) : null}
            {application.animal?.name || 'Unknown'}
          </div>
        </td>
        <td>{application.applicant?.full_name || '—'}</td>
        <td><StatusBadge status={application.status} /></td>
        <td>{application.start_date || '—'} → {application.end_date || '—'}</td>
        <td>{(application.created_at || '').slice(0, 10)}</td>
        <td className="dashActionsCell">
          <span className="dashActionsRow">
            {application.status === 'pending' && (
              <button className="dashBtn dashBtnPrimary" onClick={() => setStatus('approved')}>Approve</button>
            )}
            {application.status === 'approved' && (
              <button className="dashBtn dashBtnPrimary" onClick={() => setStatus('active')}>Start fostering</button>
            )}
            {application.status === 'active' && (
              <button className="dashBtn" onClick={() => setStatus('completed')}>Mark completed</button>
            )}
            {application.status !== 'declined' && application.status !== 'completed' && (
              <button className="dashBtn dashBtnDanger" onClick={() => setStatus('declined')}>Decline</button>
            )}
            <button className="dashBtn" onClick={() => setExpanded((v) => !v)}>{expanded ? 'Hide' : 'Monitor'}</button>
          </span>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={6} className="dashExpandPanel">
            {error && <div className="ui-error">{error}</div>}

            <div className="dashReviewSection">
              <div className="dashReviewSectionTitle">Applicant Details</div>
              <dl className="dashInfoList">
                <div><dt>Full name</dt><dd>{application.full_name || application.applicant?.full_name || '—'}</dd></div>
                <div><dt>Address</dt><dd>{application.address || '—'}</dd></div>
                <div><dt>Occupation</dt><dd>{application.occupation || '—'}</dd></div>
                <div><dt>Housing type</dt><dd>{application.housing_type || '—'}</dd></div>
                <div><dt>Email</dt><dd>{application.applicant?.email || '—'}</dd></div>
                <div><dt>Phone</dt><dd>{application.applicant?.phone || '—'}</dd></div>
                <div className="dashInfoFull"><dt>Pet experience</dt><dd>{application.pet_experience || '—'}</dd></div>
                <div className="dashInfoFull"><dt>Why foster?</dt><dd>{application.reason || '—'}</dd></div>
              </dl>
            </div>

            <MonitoringPanel application={application} onSaved={onChanged} />
          </td>
        </tr>
      )}
    </>
  );
}

export default function FosterRequestsAdmin() {
  const [applications, setApplications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [status, setStatusFilter] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  // Changing the status filter starts a fresh result set, so jump back to page 1.
  const changeStatus = (value) => {
    setPage(1);
    setStatusFilter(value);
  };

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListFosterApplications({ status, page })
      .then((data) => {
        if (!mounted) return;
        setApplications(data?.data || []);
        setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load foster requests.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [status, refreshKey, page]);

  // Keep the current page on refresh (status changes / monitoring saves); only the status
  // filter resets the page.
  const refresh = () => setRefreshKey((k) => k + 1);

  return (
    <>
      <h2 className="dashSectionTitle"><Home size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Foster Requests</h2>
      {error && <div className="ui-error">{error}</div>}

      <div className="dashFilterBar">
        <select className="ui-input" style={{ maxWidth: 180 }} aria-label="Filter foster requests by status" value={status} onChange={(e) => changeStatus(e.target.value)}>
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : applications.length === 0 ? (
        <div className="ui-empty">No foster applications match this filter.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>Animal</th>
                <th>Applicant</th>
                <th>Status</th>
                <th>Foster period</th>
                <th>Submitted</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {applications.map((a) => (
                <ApplicationRow key={a.id} application={a} onChanged={refresh} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && applications.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}
