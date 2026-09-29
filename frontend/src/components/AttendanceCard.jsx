import { useEffect, useState } from 'react';
import { Clock } from 'lucide-react';
import { clockIn, clockOut, formatDateTime, formatDuration, formatTime, getMyAttendance } from '../lib/attendanceApi';

const styles = `
  .attCard { border: 1px solid var(--line); border-radius: 12px; padding: 1rem 1.1rem; margin-bottom: 1.2rem; background: var(--surface, transparent); }
  .attHead { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
  .attStatus { display: flex; align-items: center; gap: 0.6rem; font-weight: 600; }
  .attDot { width: 10px; height: 10px; border-radius: 50%; background: var(--line); flex: none; }
  .attDotOn { background: #2e9e57; box-shadow: 0 0 0 4px rgba(46, 158, 87, 0.18); }
  .attSub { font-size: 0.85rem; color: var(--muted); margin-top: 0.2rem; font-weight: 400; }
  .attStats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.6rem; margin-top: 0.9rem; }
  .attStat { border: 1px solid var(--line); border-radius: 10px; padding: 0.55rem 0.7rem; }
  .attStatValue { font-size: 1.15rem; font-weight: 700; }
  .attStatLabel { font-size: 0.75rem; color: var(--muted); }
  .attRecent { margin-top: 0.9rem; }
  .attRecent summary { cursor: pointer; font-size: 0.85rem; color: var(--muted); }
  .attRecentList { list-style: none; padding: 0; margin: 0.5rem 0 0; font-size: 0.85rem; }
  .attRecentList li { display: flex; justify-content: space-between; gap: 1rem; padding: 0.35rem 0; border-bottom: 1px solid var(--line); }
  .attRecentList li:last-child { border-bottom: none; }
  @media (max-width: 480px) { .attStats { grid-template-columns: 1fr 1fr; } }
`;

const hours = (h) => `${Number(h || 0).toLocaleString(undefined, { maximumFractionDigits: 1 })}h`;

/**
 * The signed-in volunteer's / staff member's time clock: Time in / Time out, how long they've
 * been on duty, their hours this week, this month and overall, and their recent shifts.
 * Renders nothing for someone without a personnel record.
 */
export default function AttendanceCard({ onChanged }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [now, setNow] = useState(() => Date.now());

  const load = () => getMyAttendance()
    .then((res) => setData(res?.attendance || null))
    .catch(() => setData(null))
    .finally(() => setLoading(false));

  useEffect(() => { load(); }, []);

  // Tick once a minute while on duty so the running time stays current.
  useEffect(() => {
    if (!data?.on_duty) return undefined;
    const t = setInterval(() => setNow(Date.now()), 60000);
    return () => clearInterval(t);
  }, [data?.on_duty]);

  if (loading || !data) return null;

  const toggle = async () => {
    setBusy(true);
    setError('');
    try {
      const res = data.on_duty ? await clockOut() : await clockIn();
      setData(res?.attendance || null);
      setNow(Date.now());
      onChanged?.();
    } catch (err) {
      setError(err?.message || 'Could not update your attendance. Please try again.');
      load();
    } finally {
      setBusy(false);
    }
  };

  const runningMinutes = data.on_duty && data.open_since
    ? Math.max(0, Math.floor((now - new Date(data.open_since).getTime()) / 60000))
    : null;

  return (
    <div className="attCard">
      <style>{styles}</style>
      {error && <div className="ui-error">{error}</div>}
      <div className="attHead">
        <div>
          <div className="attStatus">
            <span className={'attDot' + (data.on_duty ? ' attDotOn' : '')} aria-hidden="true" />
            <Clock size={16} aria-hidden="true" />
            {data.on_duty ? 'On duty' : 'Off duty'}
          </div>
          <div className="attSub">
            {data.on_duty
              ? `Since ${formatTime(data.open_since)} · ${formatDuration(runningMinutes)} so far`
              : 'Clock in when you start your shift at the shelter.'}
          </div>
        </div>
        <button
          type="button"
          className={data.on_duty ? 'dashBtn dashBtnDanger' : 'ui-btn-primary'}
          onClick={toggle}
          disabled={busy}
        >
          {busy ? 'Saving…' : data.on_duty ? 'Time out' : 'Time in'}
        </button>
      </div>

      <div className="attStats">
        <div className="attStat"><div className="attStatValue">{hours(data.week_hours)}</div><div className="attStatLabel">This week</div></div>
        <div className="attStat"><div className="attStatValue">{hours(data.month_hours)}</div><div className="attStatLabel">This month</div></div>
        <div className="attStat"><div className="attStatValue">{hours(data.hours_rendered)}</div><div className="attStatLabel">Total hours rendered</div></div>
      </div>

      {data.recent?.length > 0 && (
        <details className="attRecent">
          <summary>Recent shifts</summary>
          <ul className="attRecentList">
            {data.recent.map((r) => (
              <li key={r.id}>
                <span>{formatDateTime(r.time_in)}{r.time_out ? ` – ${formatTime(r.time_out)}` : ' – now'}</span>
                <span>{r.time_out ? formatDuration(r.minutes) : 'On duty'}</span>
              </li>
            ))}
          </ul>
        </details>
      )}
      <p className="ui-muted" style={{ fontSize: '0.75rem', margin: '0.7rem 0 0' }}>
        A shift counts for up to {data.max_self_hours} hours. If you forget to time out, ask an admin to correct it.
      </p>
    </div>
  );
}
