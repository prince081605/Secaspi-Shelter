import { useEffect, useState } from 'react';
import { adminListRescueReports, adminUpdateRescueReport } from '../../lib/rescueApi';
import StatusBadge from '../../components/StatusBadge';

const STATUSES = ['pending', 'assigned', 'in_progress', 'resolved'];
const NEXT_STATUS = { pending: 'assigned', assigned: 'in_progress', in_progress: 'resolved' };
const NEXT_LABEL = { pending: 'Mark assigned', assigned: 'Mark in progress', in_progress: 'Mark resolved' };

function photoSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

function UrgencyBadge({ urgency }) {
  const u = String(urgency || '').toLowerCase();
  const cls = u === 'critical' || u === 'high' ? 'badge badgeOrange' : 'badge';
  return <span className={cls}>{urgency}</span>;
}

function TriagePanel({ report, onSaved }) {
  const [assignedTo, setAssignedTo] = useState(report.assigned_to || '');
  const [notes, setNotes] = useState(report.admin_notes || '');
  const [state, setState] = useState({ status: 'idle', error: '' });

  const save = async (extra = {}) => {
    setState({ status: 'loading', error: '' });
    try {
      await adminUpdateRescueReport(report.id, {
        assigned_to: assignedTo || null,
        admin_notes: notes || null,
        ...extra,
      });
      setState({ status: 'idle', error: '' });
      onSaved();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to save.' });
    }
  };

  const nextStatus = NEXT_STATUS[report.status];

  return (
    <div style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      <div className="dashFormGrid">
        <div><strong>Contact:</strong> {report.contact_number || '—'}</div>
        <div><strong>Location:</strong> {report.location || '—'}</div>
      </div>
      <div style={{ marginTop: 6 }}><strong>Description:</strong> {report.description || '—'}</div>
      {report.photo_url && (
        <img src={photoSrc(report.photo_url)} alt="" className="dashThumbLg" style={{ marginTop: 8 }} />
      )}
      <div className="dashFormGrid" style={{ marginTop: 10 }}>
        <div className="ui-field">
          <label className="ui-label">Assigned team / person</label>
          <input className="ui-input" value={assignedTo} onChange={(e) => setAssignedTo(e.target.value)} placeholder="e.g. Team Alpha" />
        </div>
        <div className="ui-field" style={{ gridColumn: '1 / -1' }}>
          <label className="ui-label">Notes</label>
          <textarea className="ui-input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
      </div>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <button className="dashBtn" type="button" onClick={() => save()} disabled={state.status === 'loading'}>
          {state.status === 'loading' ? 'Saving…' : 'Save assignment & notes'}
        </button>
        {nextStatus && (
          <button className="dashBtn dashBtnPrimary" type="button" onClick={() => save({ status: nextStatus })} disabled={state.status === 'loading'}>
            {NEXT_LABEL[report.status]}
          </button>
        )}
      </div>
    </div>
  );
}

function ReportRow({ report, onChanged }) {
  const [expanded, setExpanded] = useState(false);

  return (
    <>
      <tr>
        <td>{report.reporter_name || 'Anonymous'}</td>
        <td>{report.location}</td>
        <td><UrgencyBadge urgency={report.urgency} /></td>
        <td><StatusBadge status={report.status} /></td>
        <td>{report.assigned_to || '—'}</td>
        <td>{(report.created_at || '').slice(0, 10)}</td>
        <td>
          <button className="dashBtn" onClick={() => setExpanded((v) => !v)}>{expanded ? 'Hide' : 'Triage'}</button>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={7} className="dashExpandPanel">
            <TriagePanel report={report} onSaved={onChanged} />
          </td>
        </tr>
      )}
    </>
  );
}

export default function RescueReportsAdmin() {
  const [reports, setReports] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [status, setStatusFilter] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListRescueReports({ status })
      .then((data) => {
        if (!mounted) return;
        setReports(data?.data || []);
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load rescue reports.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [status, refreshKey]);

  const refresh = () => setRefreshKey((k) => k + 1);

  return (
    <>
      <div className="dashSectionTitle">🚨 Rescue Reports</div>
      {error && <div className="ui-error">{error}</div>}

      <div style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
        <select className="ui-input" style={{ maxWidth: 180 }} aria-label="Filter rescue reports by status" value={status} onChange={(e) => setStatusFilter(e.target.value)}>
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
        </select>
      </div>

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : reports.length === 0 ? (
        <div className="ui-empty">No rescue reports match this filter.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>Reporter</th>
                <th>Location</th>
                <th>Urgency</th>
                <th>Status</th>
                <th>Assigned to</th>
                <th>Submitted</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {reports.map((r) => (
                <ReportRow key={r.id} report={r} onChanged={refresh} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
