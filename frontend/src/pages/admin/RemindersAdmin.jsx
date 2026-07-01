import { useEffect, useState } from 'react';
import { adminListReminders, adminUpdateReminder } from '../../lib/remindersApi';
import { Bell, PartyPopper } from 'lucide-react';

export default function RemindersAdmin({ onChanged }) {
  const [reminders, setReminders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListReminders(30)
      .then((data) => {
        if (!mounted) return;
        setReminders(data?.reminders || []);
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load reminders.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [refreshKey]);

  const markDone = async (id) => {
    setError('');
    try {
      await adminUpdateReminder(id, 'completed');
      setRefreshKey((k) => k + 1);
      onChanged?.();
    } catch (err) {
      setError(err?.message || 'Failed to update reminder.');
    }
  };

  return (
    <>
      <h2 className="dashSectionTitle"><Bell size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Health Reminders</h2>
      <p className="ui-muted" style={{ marginTop: -4, marginBottom: 12, fontSize: '0.9rem' }}>
        Vaccination boosters and follow-ups due within 30 days. Overdue items are highlighted.
      </p>
      {error && <div className="ui-error">{error}</div>}

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : reminders.length === 0 ? (
        <div className="ui-empty">No upcoming reminders. You're all caught up! <PartyPopper size={16} style={{ verticalAlign: '-3px' }} /></div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>Reminder</th>
                <th>Animal</th>
                <th>Due date</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {reminders.map((r) => (
                <tr key={r.id} style={r.is_overdue ? { background: 'rgba(180, 35, 24, 0.06)' } : undefined}>
                  <td>{r.title}</td>
                  <td>{r.animal?.name || '—'}</td>
                  <td>
                    {r.reminder_date}
                    {r.is_overdue && <span className="badge badgeOrange" style={{ marginLeft: 8 }}>overdue</span>}
                  </td>
                  <td><span className="badge badgeSky">{r.status}</span></td>
                  <td className="dashActionsCell">
                    <span className="dashActionsRow">
                      <button className="dashBtn dashBtnPrimary" onClick={() => markDone(r.id)}>Mark done</button>
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
