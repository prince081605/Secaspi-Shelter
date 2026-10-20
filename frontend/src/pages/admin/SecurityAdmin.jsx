import { useEffect, useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import {
  adminBlockReporter,
  adminListBlockedReporters,
  adminListFlaggedReporters,
  adminUnblockReporter,
} from '../../lib/rescueApi';
import DashCard from '../../components/DashCard';
import useConfirm from '../../lib/useConfirm';
import useIsMobile from '../../lib/useIsMobile';

const shortDate = (value) => (value || '').slice(0, 10);

/**
 * The System Admin's Security page. The desk closes fake rescue reports as invalid; here the
 * System Admin sees who keeps sending them and blocks that contact number or account (never a
 * shared connection), so their new reports are refused with the shelter's number. Unblocking is
 * here too.
 */
export default function SecurityAdmin() {
  const confirm = useConfirm();
  const isMobile = useIsMobile();
  const [flagged, setFlagged] = useState([]);
  const [blocked, setBlocked] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const refresh = () => setRefreshKey((k) => k + 1);

  useEffect(() => {
    let mounted = true;
    Promise.all([adminListFlaggedReporters(), adminListBlockedReporters()])
      .then(([f, b]) => {
        if (!mounted) return;
        setFlagged(f?.reporters || []);
        setBlocked(b?.blocked || []);
        setError('');
      })
      .catch((err) => { if (mounted) setError(err?.message || 'Failed to load the security lists.'); })
      .finally(() => { if (mounted) setLoading(false); });
    return () => { mounted = false; };
  }, [refreshKey]);

  const block = async (reporter) => {
    const ok = await confirm({
      title: 'Block this reporter?',
      message: 'Their new rescue reports will be refused, with the shelter’s phone number to call instead. Earlier reports are kept. You can unblock them below.',
      confirmLabel: 'Block reporter',
      tone: 'danger',
      summary: [
        { label: 'Reporter', value: reporter.label },
        { label: 'Fake reports', value: String(reporter.fake_reports) },
      ],
    });
    if (!ok) return;
    const reason = window.prompt('Why are you blocking this reporter? (kept for other System Admins)', '');
    if (reason === null) return;
    setError('');
    try {
      await adminBlockReporter(reporter.report_id, reason.trim() || null);
      refresh();
    } catch (err) {
      setError(err?.message || 'Failed to block the reporter.');
    }
  };

  const unblock = async (entry) => {
    const ok = await confirm({
      title: 'Unblock this reporter?',
      message: 'Their new rescue reports will be accepted again.',
      confirmLabel: 'Unblock',
      summary: [{ label: 'Reporter', value: entry.label }, { label: 'Reason', value: entry.reason || '' }],
    });
    if (!ok) return;
    setError('');
    try {
      await adminUnblockReporter(entry.id);
      refresh();
    } catch (err) {
      setError(err?.message || 'Failed to unblock.');
    }
  };

  const blockButton = (r) => (r.blocked
    ? <span className="badge badgeOrange">Blocked</span>
    : <button className="dashBtn dashBtnDanger" type="button" onClick={() => block(r)}>Block</button>);
  const unblockButton = (b) => <button className="dashBtn" type="button" onClick={() => unblock(b)}>Unblock</button>;

  return (
    <>
      <h2 className="dashSectionTitle"><ShieldCheck size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Security</h2>
      <p className="ui-muted" style={{ marginTop: -4, marginBottom: 12, fontSize: '0.9rem' }}>
        Reporters whose rescue reports the desk closed as fake. Blocking turns away their new reports; their old ones are kept.
      </p>
      {error && <div className="ui-error">{error}</div>}

      <h3 className="dashSectionTitle" style={{ fontSize: '1rem' }}>Reporters with fake reports</h3>
      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : flagged.length === 0 ? (
        <div className="ui-empty">No reports have been closed as fake.</div>
      ) : isMobile ? (
        <div className="dashCardList">
          {flagged.map((r) => (
            <DashCard
              key={r.report_id}
              title={r.label}
              fields={[
                { label: 'Fake reports', value: r.fake_reports },
                { label: 'Latest', value: `${shortDate(r.last_report_at)} · report #${r.report_id}` },
              ]}
              actions={blockButton(r)}
            />
          ))}
        </div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr><th>Reporter</th><th>Fake reports</th><th>Latest</th><th>Actions</th></tr>
            </thead>
            <tbody>
              {flagged.map((r) => (
                <tr key={r.report_id}>
                  <td>{r.label}</td>
                  <td>{r.fake_reports}</td>
                  <td className="dashNowrap">{shortDate(r.last_report_at)} <span className="ui-muted">· #{r.report_id}</span></td>
                  <td>{blockButton(r)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <h3 className="dashSectionTitle" style={{ fontSize: '1rem', marginTop: 20 }}>Blocked reporters ({blocked.length})</h3>
      {loading ? null : blocked.length === 0 ? (
        <div className="ui-empty">Nobody is blocked.</div>
      ) : isMobile ? (
        <div className="dashCardList">
          {blocked.map((b) => (
            <DashCard
              key={b.id}
              title={b.label}
              fields={[
                { label: 'Reason', value: b.reason || '—' },
                { label: 'Blocked by', value: b.blocked_by || '—' },
                { label: 'Since', value: shortDate(b.created_at) },
              ]}
              actions={unblockButton(b)}
            />
          ))}
        </div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr><th>Reporter</th><th>Reason</th><th>Blocked by</th><th>Since</th><th>Actions</th></tr>
            </thead>
            <tbody>
              {blocked.map((b) => (
                <tr key={b.id}>
                  <td>{b.label}{b.report_id ? <span className="ui-muted"> · from report #{b.report_id}</span> : null}</td>
                  <td>{b.reason || '—'}</td>
                  <td>{b.blocked_by || '—'}</td>
                  <td className="dashNowrap">{shortDate(b.created_at)}</td>
                  <td>{unblockButton(b)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
