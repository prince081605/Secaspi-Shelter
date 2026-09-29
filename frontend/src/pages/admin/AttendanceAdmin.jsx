import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import {
  adminCreateAttendance,
  adminDeleteAttendance,
  adminListAttendance,
  adminUpdateAttendance,
  formatDateTime,
  formatDuration,
  formatTime,
  fromLocalInput,
  toLocalInput,
} from '../../lib/attendanceApi';
import { adminListVolunteers } from '../../lib/volunteersApi';
import useConfirm from '../../lib/useConfirm';
import useIsMobile from '../../lib/useIsMobile';
import DashCard from '../../components/DashCard';
import Pagination from '../../components/Pagination';

const TYPE_LABEL = { volunteer: 'Volunteer', staff: 'Staff' };

const minutesSince = (value) => Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 60000));

/** Add a shift for someone, or correct an existing one. Admin only. */
function RecordForm({ record, onCancel, onSaved }) {
  const isEdit = Boolean(record);
  const [people, setPeople] = useState([]);
  const [volunteerId, setVolunteerId] = useState(record?.volunteer?.id || '');
  const [timeIn, setTimeIn] = useState(toLocalInput(record?.time_in));
  const [timeOut, setTimeOut] = useState(toLocalInput(record?.time_out));
  const [notes, setNotes] = useState(record?.notes || '');
  const [state, setState] = useState({ status: 'idle', error: '' });

  useEffect(() => {
    if (isEdit) return;
    Promise.all([
      adminListVolunteers({ type: 'volunteer', per_page: 100 }),
      adminListVolunteers({ type: 'staff', per_page: 100 }),
    ])
      .then(([v, s]) => setPeople([...(v?.data || []), ...(s?.data || [])]
        .sort((a, b) => (a.user?.full_name || '').localeCompare(b.user?.full_name || ''))))
      .catch(() => setState({ status: 'error', error: 'Could not load personnel.' }));
  }, [isEdit]);

  const submit = async (e) => {
    e.preventDefault();
    setState({ status: 'loading', error: '' });
    const payload = { time_in: fromLocalInput(timeIn), time_out: fromLocalInput(timeOut), notes: notes || null };
    try {
      if (isEdit) await adminUpdateAttendance(record.id, payload);
      else await adminCreateAttendance(volunteerId, payload);
      onSaved();
    } catch (err) {
      const fieldError = Object.values(err?.data?.errors || {})[0]?.[0];
      setState({ status: 'error', error: fieldError || err?.message || 'Failed to save the record.' });
    }
  };

  return (
    <form onSubmit={submit} className="dashCard" style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      <div className="dashFormGrid">
        <div className="ui-field">
          <label className="ui-label ui-label-required">Person</label>
          {isEdit ? (
            <input className="ui-input" value={record.volunteer?.full_name || '—'} disabled />
          ) : (
            <select className="ui-input" value={volunteerId} onChange={(e) => setVolunteerId(e.target.value)} required>
              <option value="">Select a volunteer or staff member</option>
              {people.map((p) => (
                <option key={p.id} value={p.id}>{p.user?.full_name || `#${p.id}`} ({TYPE_LABEL[p.type] || p.type})</option>
              ))}
            </select>
          )}
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Time in</label>
          <input className="ui-input" type="datetime-local" value={timeIn} onChange={(e) => setTimeIn(e.target.value)} required />
        </div>
        <div className="ui-field">
          <label className="ui-label">Time out</label>
          <input className="ui-input" type="datetime-local" value={timeOut} onChange={(e) => setTimeOut(e.target.value)} />
          <p className="ui-muted" style={{ fontSize: '0.78rem', margin: '0.3rem 0 0' }}>Leave empty if they're still on duty.</p>
        </div>
        <div className="ui-field">
          <label className="ui-label">Note</label>
          <input className="ui-input" maxLength={255} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. Forgot to clock out" />
        </div>
      </div>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <button className="ui-btn-primary" type="submit" disabled={state.status === 'loading'}>
          {state.status === 'loading' ? 'Saving…' : isEdit ? 'Save changes' : 'Add record'}
        </button>
        <button className="dashBtn" type="button" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

/**
 * Personnel → Attendance. Who's on duty right now, and the shift log with date / type filters.
 * Staff can look; admins can add, correct, close and delete records.
 */
export default function AttendanceAdmin({ isAdmin = false }) {
  const confirm = useConfirm();
  const isMobile = useIsMobile();
  const [filters, setFilters] = useState({ from: '', to: '', type: '' });
  const [page, setPage] = useState(1);
  const [refreshKey, setRefreshKey] = useState(0);
  const [data, setData] = useState({ records: [], onDuty: [], meta: { current_page: 1, last_page: 1 } });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState(null);

  useEffect(() => {
    let mounted = true;
    adminListAttendance({ ...filters, page })
      .then((res) => {
        if (!mounted) return;
        setData({
          records: res?.data || [],
          onDuty: res?.on_duty || [],
          meta: { current_page: res?.current_page || 1, last_page: res?.last_page || 1 },
        });
        setError('');
      })
      .catch((err) => { if (mounted) setError(err?.message || 'Failed to load attendance.'); })
      .finally(() => { if (mounted) setLoading(false); });
    return () => { mounted = false; };
  }, [filters, page, refreshKey]);

  const refresh = () => {
    setAdding(false);
    setEditing(null);
    setRefreshKey((k) => k + 1);
  };

  const setFilter = (key) => (e) => {
    setPage(1);
    setFilters((f) => ({ ...f, [key]: e.target.value }));
  };

  const closeShift = async (r) => {
    const ok = await confirm({
      title: `Time out ${r.volunteer?.full_name || 'this person'} now?`,
      message: 'Closes their open shift at the current time and adds it to their hours.',
      confirmLabel: 'Time out',
      summary: [
        { label: 'On duty since', value: formatDateTime(r.time_in) },
        { label: 'Duration', value: formatDuration(minutesSince(r.time_in)) },
      ],
    });
    if (!ok) return;
    try {
      await adminUpdateAttendance(r.id, { time_in: r.time_in, time_out: new Date().toISOString(), notes: r.notes || 'Timed out by admin' });
      refresh();
    } catch (err) {
      setError(Object.values(err?.data?.errors || {})[0]?.[0] || err?.message || 'Failed to time out.');
    }
  };

  const remove = async (r) => {
    const ok = await confirm({
      title: 'Delete this attendance record?',
      message: 'Its hours come off the person’s total. This cannot be undone.',
      confirmLabel: 'Delete record',
      tone: 'danger',
      summary: [
        { label: 'Person', value: r.volunteer?.full_name },
        { label: 'Time in', value: formatDateTime(r.time_in) },
        { label: 'Duration', value: formatDuration(r.minutes) },
      ],
    });
    if (!ok) return;
    try {
      await adminDeleteAttendance(r.id);
      refresh();
    } catch (err) {
      setError(err?.message || 'Failed to delete the record.');
    }
  };

  const actions = (r) => (isAdmin ? (
    <>
      {!r.time_out && <button className="dashBtn dashBtnPrimary" onClick={() => closeShift(r)}>Time out</button>}
      <button className="dashBtn" onClick={() => { setEditing(r); setAdding(false); }}>Edit</button>
      <button className="dashBtn dashBtnDanger" aria-label="Delete attendance record" onClick={() => remove(r)}><X size={14} /></button>
    </>
  ) : null);

  const { records, onDuty, meta } = data;

  return (
    <>
      {error && <div className="ui-error">{error}</div>}

      <h3 className="dashSubSectionTitle" style={{ marginTop: 0 }}>On duty now ({onDuty.length})</h3>
      {onDuty.length === 0 ? (
        <div className="ui-empty" style={{ marginBottom: 16 }}>Nobody is clocked in right now.</div>
      ) : (
        <div className="dashCardList" style={{ marginBottom: 16 }}>
          {onDuty.map((r) => (
            <DashCard
              key={r.id}
              title={r.volunteer?.full_name || '—'}
              subtitle={TYPE_LABEL[r.volunteer?.type] || ''}
              fields={[
                { label: 'Since', value: formatTime(r.time_in) },
                { label: 'So far', value: formatDuration(minutesSince(r.time_in)) },
              ]}
              actions={isAdmin ? <button className="dashBtn dashBtnPrimary" onClick={() => closeShift(r)}>Time out</button> : null}
            />
          ))}
        </div>
      )}

      <h3 className="dashSubSectionTitle">Attendance log</h3>
      <div className="dashFilterBar" style={{ flexWrap: 'wrap' }}>
        <label className="dashFilterField">
          <span className="dashFilterLabel">From</span>
          <input className="ui-input" type="date" style={{ maxWidth: 170 }} value={filters.from} onChange={setFilter('from')} />
        </label>
        <label className="dashFilterField">
          <span className="dashFilterLabel">To</span>
          <input className="ui-input" type="date" style={{ maxWidth: 170 }} value={filters.to} onChange={setFilter('to')} />
        </label>
        <label className="dashFilterField">
          <span className="dashFilterLabel">Personnel</span>
          <select className="ui-input" style={{ maxWidth: 180 }} value={filters.type} onChange={setFilter('type')}>
            <option value="">Volunteers & staff</option>
            <option value="volunteer">Volunteers</option>
            <option value="staff">Staff</option>
          </select>
        </label>
        {isAdmin && (
          <button className="dashBtn dashBtnPrimary" onClick={() => { setAdding((v) => !v); setEditing(null); }}>
            {adding ? 'Close' : '+ Add record'}
          </button>
        )}
      </div>

      {adding && <RecordForm onCancel={() => setAdding(false)} onSaved={refresh} />}
      {editing && <RecordForm key={editing.id} record={editing} onCancel={() => setEditing(null)} onSaved={refresh} />}

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : records.length === 0 ? (
        <div className="ui-empty">No attendance records match these filters.</div>
      ) : isMobile ? (
        <div className="dashCardList" style={{ marginTop: 10 }}>
          {records.map((r) => (
            <DashCard
              key={r.id}
              title={r.volunteer?.full_name || '—'}
              subtitle={TYPE_LABEL[r.volunteer?.type] || ''}
              fields={[
                { label: 'Time in', value: formatDateTime(r.time_in) },
                { label: 'Time out', value: r.time_out ? formatDateTime(r.time_out) : 'On duty' },
                { label: 'Duration', value: formatDuration(r.minutes) },
                r.notes && { label: 'Note', value: r.notes },
                r.recorded_by && { label: 'Recorded by', value: r.recorded_by },
              ]}
              actions={actions(r)}
            />
          ))}
        </div>
      ) : (
        <div className="dashTableWrap" style={{ marginTop: 10 }}>
          <table className="dashTable">
            <thead>
              <tr>
                <th>Person</th>
                <th>Type</th>
                <th>Time in</th>
                <th>Time out</th>
                <th>Duration</th>
                <th>Note</th>
                {isAdmin && <th></th>}
              </tr>
            </thead>
            <tbody>
              {records.map((r) => (
                <tr key={r.id}>
                  <td>{r.volunteer?.full_name || '—'}</td>
                  <td>{TYPE_LABEL[r.volunteer?.type] || '—'}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{formatDateTime(r.time_in)}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{r.time_out ? formatDateTime(r.time_out) : <strong>On duty</strong>}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{formatDuration(r.minutes)}</td>
                  <td>
                    {r.notes || '—'}
                    {r.recorded_by && <div className="ui-muted" style={{ fontSize: 12 }}>Recorded by {r.recorded_by}</div>}
                  </td>
                  {isAdmin && <td className="dashActionsCell"><span className="dashActionsRow">{actions(r)}</span></td>}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && records.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}
