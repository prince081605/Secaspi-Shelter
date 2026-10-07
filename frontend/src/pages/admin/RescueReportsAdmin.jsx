import { useEffect, useRef, useState } from 'react';
import { MapPin, Siren } from 'lucide-react';
import { MapContainer, TileLayer, CircleMarker, Popup } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';
import { adminListRescueReports, adminMarkRescueReportRead, adminUpdateRescueReport } from '../../lib/rescueApi';
import { adminListTeams } from '../../lib/volunteersApi';
import StatusBadge from '../../components/StatusBadge';
import useConfirm from '../../lib/useConfirm';
import Pagination from '../../components/Pagination';
import DashCard from '../../components/DashCard';
import useIsMobile from '../../lib/useIsMobile';

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
          <div><img src={photoSrc(report.photo_url)} alt="Rescue report" style={{ maxWidth: 'min(320px, 100%)', marginTop: 6, borderRadius: 8 }} /></div>
        </div>
      )}

      <div style={{ marginTop: 10 }}>
        <strong><MapPin size={15} style={{ verticalAlign: '-3px' }} /> Exact location:</strong>
        {hasPin ? (
          <div style={{ height: 'clamp(220px, 45vh, 300px)', marginTop: 6, borderRadius: 10, overflow: 'hidden' }}>
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

// Who's handling a report: its team, or — on reports from before teams existed — the free text
// that was typed in then.
const assignedLabel = (report) => report.team?.name || report.assigned_to || '—';

function TriagePanel({ report, teams, onSaved }) {
  const confirm = useConfirm();
  const [teamId, setTeamId] = useState(report.team_id ? String(report.team_id) : '');
  const [notes, setNotes] = useState(report.admin_notes || '');
  // Active teams to choose from, plus the report's current team if it has since been archived.
  const teamChoices = teams.filter((t) => t.is_active || String(t.id) === String(report.team_id));
  const teamName = teamChoices.find((t) => String(t.id) === teamId)?.name || '';
  const [state, setState] = useState({ status: 'idle', error: '' });

  const save = async (extra = {}) => {
    // The same handler saves triage notes and advances the report's status, so the prompt has
    // to say which of the two the admin just pressed.
    const advancing = Boolean(extra.status);
    const ok = await confirm({
      title: advancing ? `${NEXT_LABEL[report.status]}?` : 'Save this triage?',
      message: advancing
        ? 'The reporter sees the new status on their report, along with anything assigned below.'
        : 'The assignment and notes below are saved to the report.',
      confirmLabel: advancing ? NEXT_LABEL[report.status] : 'Save triage',
      summary: [
        { label: 'Location', value: report.location },
        { label: 'Team', value: teamName },
        { label: 'Team notified', value: teamId && String(teamId) !== String(report.team_id || '') ? 'Yes — every member' : '' },
        { label: 'New status', value: advancing ? extra.status.replace('_', ' ') : '' },
      ],
    });
    if (!ok) return;
    setState({ status: 'loading', error: '' });
    try {
      await adminUpdateRescueReport(report.id, {
        team_id: teamId ? Number(teamId) : null,
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
          <label className="ui-label">Assigned team</label>
          <select className="ui-input" value={teamId} onChange={(e) => setTeamId(e.target.value)}>
            <option value="">Not assigned</option>
            {teamChoices.map((t) => (
              <option key={t.id} value={t.id}>{t.name}{t.is_active ? '' : ' (archived)'} — {t.members_count} member{t.members_count === 1 ? '' : 's'}</option>
            ))}
          </select>
          {teamChoices.length === 0 && (
            <span className="ui-muted" style={{ fontSize: 12, marginTop: 4 }}>No teams yet. Create one under Personnel → Teams.</span>
          )}
          {report.assigned_to && (
            <span className="ui-muted" style={{ fontSize: 12, marginTop: 4 }}>Noted before teams existed: {report.assigned_to}</span>
          )}
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

function ReportRow({ report, teams, onChanged, onUnreadChanged, isMobile }) {
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

  const actions = (
    <>
      <button className="dashBtn" onClick={() => open('detail')}>{mode === 'detail' ? 'Hide' : 'Detail'}</button>
      <button className="dashBtn" onClick={() => open('triage')}>{mode === 'triage' ? 'Hide' : 'Triage'}</button>
    </>
  );
  const panel = mode === 'detail'
    ? <DetailPanel report={report} />
    : <TriagePanel report={report} teams={teams} onSaved={handleInteracted} />;

  if (isMobile) {
    return (
      <>
        <DashCard
          accent={isUnread ? 'unread' : undefined}
          title={report.reporter_name || 'Anonymous'}
          subtitle={report.location}
          fields={[
            { label: 'Urgency', value: <UrgencyBadge urgency={report.urgency} /> },
            { label: 'Status', value: <StatusBadge status={report.status} /> },
            { label: 'Assigned to', value: assignedLabel(report) },
            { label: 'Submitted', value: (report.created_at || '').slice(0, 10) },
          ]}
          actions={actions}
        />
        {mode && <div className="dashCardExpand">{panel}</div>}
      </>
    );
  }

  return (
    <>
      <tr className={isUnread ? 'dashRowUnread' : ''}>
        <td>{report.reporter_name || 'Anonymous'}</td>
        <td>{report.location}</td>
        <td><UrgencyBadge urgency={report.urgency} /></td>
        <td><StatusBadge status={report.status} /></td>
        <td>{assignedLabel(report)}</td>
        <td className="dashNowrap">{(report.created_at || '').slice(0, 10)}</td>
        <td style={{ whiteSpace: 'nowrap' }}>
          <button className="dashBtn" onClick={() => open('detail')}>{mode === 'detail' ? 'Hide' : 'Detail'}</button>
          <button className="dashBtn" style={{ marginLeft: 6 }} onClick={() => open('triage')}>{mode === 'triage' ? 'Hide' : 'Triage'}</button>
        </td>
      </tr>
      {mode && (
        <tr>
          <td colSpan={7} className="dashExpandPanel">{panel}</td>
        </tr>
      )}
    </>
  );
}

export default function RescueReportsAdmin({ onUnreadChanged }) {
  const isMobile = useIsMobile();
  const [reports, setReports] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [status, setStatusFilter] = useState('');
  const [teamFilter, setTeamFilter] = useState('');
  const [teams, setTeams] = useState([]);
  const [refreshKey, setRefreshKey] = useState(0);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  // Changing the status filter starts a fresh result set, so jump back to page 1.
  const changeStatus = (value) => {
    setPage(1);
    setStatusFilter(value);
  };

  // A refresh after an action (marking a report read, saving triage) reloads quietly: showing
  // "Loading…" would unmount the table and close the panel the admin just opened.
  const lastRefreshKey = useRef(refreshKey);

  useEffect(() => {
    let mounted = true;
    if (lastRefreshKey.current === refreshKey) setLoading(true);
    lastRefreshKey.current = refreshKey;
    adminListRescueReports({ status, team_id: teamFilter, page })
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
  }, [status, teamFilter, refreshKey, page]);

  // Teams to assign reports to (Personnel → Teams), and to filter by.
  useEffect(() => {
    let mounted = true;
    adminListTeams().then((res) => { if (mounted) setTeams(res?.teams || []); }).catch(() => {});
    return () => { mounted = false; };
  }, []);

  // Keep the current page on refresh (mark-read / triage) so an open row panel isn't collapsed;
  // only the status filter resets the page (see changeStatus).
  const refresh = () => setRefreshKey((k) => k + 1);

  return (
    <>
      <h2 className="dashSectionTitle"><Siren size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Rescue Reports</h2>
      {error && <div className="ui-error">{error}</div>}

      <div className="dashFilterBar">
        <select className="ui-input" style={{ maxWidth: 180 }} aria-label="Filter rescue reports by status" value={status} onChange={(e) => changeStatus(e.target.value)}>
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
        </select>
        {teams.length > 0 && (
          <select className="ui-input" style={{ maxWidth: 200 }} aria-label="Filter rescue reports by team" value={teamFilter} onChange={(e) => { setPage(1); setTeamFilter(e.target.value); }}>
            <option value="">All teams</option>
            {teams.map((t) => <option key={t.id} value={t.id}>{t.name}{t.is_active ? '' : ' (archived)'}</option>)}
          </select>
        )}
      </div>

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : reports.length === 0 ? (
        <div className="ui-empty">No rescue reports match this filter.</div>
      ) : isMobile ? (
        <div className="dashCardList">
          {reports.map((r) => (
            <ReportRow key={r.id} report={r} teams={teams} onChanged={refresh} onUnreadChanged={onUnreadChanged} isMobile />
          ))}
        </div>
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
                <ReportRow key={r.id} report={r} teams={teams} onChanged={refresh} onUnreadChanged={onUnreadChanged} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && reports.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}
