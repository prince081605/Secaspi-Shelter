import { useEffect, useState } from 'react';
import { ServerCog } from 'lucide-react';
import { adminGetUserSummary } from '../../lib/usersApi';
import { ADMIN_ROLE_SUMMARIES } from '../../lib/permissions';

const OTHER_ROLE_SUMMARIES = {
  user: 'Their own adoptions, donations and messages.',
  volunteer: 'Everything a user has, plus their tasks and attendance.',
  staff: 'Day-to-day shelter work and the operational reports.',
};

/**
 * The System Admin's home: how many accounts there are, by role and status, and how many
 * reporters are blocked — with the way into Users and Security. No shelter operations here; those
 * belong to the desk.
 */
export default function SystemSummary({ onOpen }) {
  const [summary, setSummary] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let mounted = true;
    adminGetUserSummary()
      .then((data) => { if (mounted) setSummary(data); })
      .catch((err) => { if (mounted) setError(err?.message || 'Failed to load the account summary.'); });
    return () => { mounted = false; };
  }, []);

  const cards = summary ? [
    { key: 'total', variant: 'purple', label: 'Accounts', value: summary.total, sub: 'Every role' },
    { key: 'suspended', variant: 'orange', label: 'Suspended', value: summary.suspended, sub: 'Cannot sign in' },
    { key: 'unverified', variant: 'sky', label: 'Unverified emails', value: summary.unverified, sub: 'Never confirmed' },
    { key: 'blocked', variant: 'dashCardGreen', label: 'Blocked reporters', value: summary.blocked_reporters, sub: 'Rescue reports refused' },
  ] : [];

  return (
    <>
      {error && <div className="ui-error">{error}</div>}
      <div className="dashGridCards">
        {cards.map((c) => (
          <div key={c.key} className={`dashCard ${c.variant}`}>
            <div className="dashCardValue">{c.value}</div>
            <div className="dashCardLabel">{c.label}</div>
            <div className="dashCardSub">{c.sub}</div>
          </div>
        ))}
      </div>

      <h2 className="dashSectionTitle"><ServerCog size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Accounts by role</h2>
      {!summary ? (
        <div className="ui-empty">{error ? '—' : 'Loading…'}</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr><th>Role</th><th>Accounts</th><th>What it can do</th></tr>
            </thead>
            <tbody>
              {summary.roles.map((r) => (
                <tr key={r.role}>
                  <td className="dashNowrap">{r.label}</td>
                  <td>{r.count}</td>
                  <td className="ui-muted">{ADMIN_ROLE_SUMMARIES[r.role] || OTHER_ROLE_SUMMARIES[r.role] || ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 12 }}>
        <button className="dashBtn dashBtnPrimary" type="button" onClick={() => onOpen?.('users')}>Manage users and roles</button>
        <button className="dashBtn" type="button" onClick={() => onOpen?.('security')}>Open Security</button>
      </div>
    </>
  );
}
