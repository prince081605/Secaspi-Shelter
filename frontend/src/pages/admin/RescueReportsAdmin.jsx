import { useEffect, useState } from 'react';
import { MapPin, Siren } from 'lucide-react';
import { MapContainer, TileLayer, CircleMarker, Popup } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';
import { adminListRescueReports, adminMarkRescueReportRead, adminUpdateRescueReport } from '../../lib/rescueApi';
import StatusBadge from '../../components/StatusBadge';
import Pagination from '../../components/Pagination';

const STATUSES = ['pending', 'assigned', 'in_progress', 'resolved'];
const NEXT_STATUS = { pending: 'assigned', assigned: 'in_progress', in_progress: 'resolved' };
const NEXT_LABEL = { pending: 'Mark assigned', assigned: 'Mark in progress', in_progress: 'Mark resolved' };
const URGENCY_COLOR = { critical: '#c0392b', high: '#e67e22', medium: '#d8a657', low: '#7c8b6b' };

function photoSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

function UrgencyBadge({ urgency }) {
  const u = String(urgency || '').toLowerCase();
  const cls = u === 'critical' || u === 'high' ? 'badge badgeOrange' : 'badge';
  return <span className={cls}>{urgency}</span>;
}

// Full report view: details, photo, and the exact spot on the map (from the report's coordinates).
function DetailPanel({ report }) {
  const hasPin = report.latitude != null && report.longitude != null;
  const center = hasPin ? [Number(report.latitude), Number(report.longitude)] : null;

  return (
    <div style={{ marginTop: 10 }}>
      <div className="dashFormGrid">
        <div><strong>Reporter:</strong> {report.reporter_name || 'Anonymous'}</div>
        <div><strong>Contact:</strong> {report.contact_number || '—'}</div>
        <div><strong>Urgency:</strong> <UrgencyBadge urgency={report.urgency} /></div>
        <div><strong>Status:</strong> <StatusBadge status={report.status} /></div>
        <div style={{ gridColumn: '1 / -1' }}><strong>Location:</strong> {report.location || '—'}</div>
      </div>
      <div style={{ marginTop: 6 }}><strong>Description:</strong> {report.description || '—'}</div>
      {report.admin_notes && <div style={{ marginTop: 6 }}><strong>Notes:</strong> {report.admin_notes}</div>}

      {report.photo_url && (
        <div style={{ marginTop: 10 }}>
          <strong>Photo:</strong>
          <div><img src={photoSrc(report.photo_url)} alt="Rescue report" style={{ maxWidth: 320, marginTop: 6, borderRadius: 8 }} /></div>
        </div>
      )}

      <div style={{ marginTop: 10 }}>
        <strong><MapPin size={15} style={{ verticalAlign: '-3px' }} /> Exact location:</strong>
        {hasPin ? (
          <div style={{ height: 300, marginTop: 6, borderRadius: 10, overflow: 'hidden' }}>
            <MapContainer center={center} zoom={16} style={{ height: '100%', width: '100%' }} scrollWheelZoom>
              <TileLayer attribution="&copy; OpenStreetMap contributors" url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
              <CircleMarker center={center} radius={11} pathOptions={{ color: URGENCY_COLOR[report.urgency] || '#c0392b', fillOpacity: 0.85 }}>
                <Popup>
                  <strong>{report.location}</strong><br />Urgency: {report.urgency}
                </Popup>
              </CircleMarker>
            </MapContainer>
          </div>
        ) : (
          <div className="ui-empty" style={{ marginTop: 6 }}>
            No precise pin was provided for this report — only the typed location above.
          </div>
        )}
      </div>
    </div>
  );
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

function ReportRow({ report, onChanged, onUnreadChanged }) {
  const [mode, setMode] = useState(''); // '' | 'triage' | 'detail'
  const isUnread = !report.read_at;

  const handleInteracted = () => {
    onChanged();
    onUnreadChanged?.();
  };

  const open = async (which) => {
    const next = mode === which ? '' : which;
    setMode(next);
    if (next && isUnread) {
      try {
        await adminMarkRescueReportRead(report.id);
        handleInteracted();
      } catch {
        // non-critical: the unread highlight just won't clear until the next interaction
      }
    }
  };

  return (
    <>
      <tr className={isUnread ? 'dashRowUnread' : ''}>
        <td>{report.reporter_name || 'Anonymous'}</td>
        <td>{report.location}</td>
        <td><UrgencyBadge urgency={report.urgency} /></td>
        <td><StatusBadge status={report.status} /></td>
        <td>{report.assigned_to || '—'}</td>
        <td>{(report.created_at || '').slice(0, 10)}</td>
        <td style={{ whiteSpace: 'nowrap' }}>
          <button className="dashBtn" onClick={() => open('detail')}>{mode === 'detail' ? 'Hide' : 'Detail'}</button>
          <button className="dashBtn" style={{ marginLeft: 6 }} onClick={() => open('triage')}>{mode === 'triage' ? 'Hide' : 'Triage'}</button>
        </td>
      </tr>
      {mode && (
        <tr>
          <td colSpan={7} className="dashExpandPanel">
            {mode === 'detail'
              ? <DetailPanel report={report} />
              : <TriagePanel report={report} onSaved={handleInteracted} />}
          </td>
        </tr>
      )}
    </>
  );
}

export default function RescueReportsAdmin({ onUnreadChanged }) {
  const [reports, setReports] = useState([]);
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
    adminListRescueReports({ status, page })
      .then((data) => {
        if (!mounted) return;
        setReports(data?.data || []);
        setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
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
  }, [status, refreshKey, page]);

  // Keep the current page on refresh (mark-read / triage) so an open row panel isn't collapsed;
  // only the status filter resets the page (see changeStatus).
  const refresh = () => setRefreshKey((k) => k + 1);

  return (
    <>
      <h2 className="dashSectionTitle"><Siren size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Rescue Reports</h2>
      {error && <div className="ui-error">{error}</div>}

      <div className="dashFilterBar">
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
                <ReportRow key={r.id} report={r} onChanged={refresh} onUnreadChanged={onUnreadChanged} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && reports.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}
