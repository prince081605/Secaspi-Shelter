import { useEffect, useState } from 'react';
import { adminListUsers, adminUpdateUser } from '../../lib/usersApi';
import StatusBadge from '../../components/StatusBadge';

const ROLES = ['admin', 'staff', 'volunteer', 'user'];
const STATUSES = ['active', 'suspended', 'pending'];

export default function UsersAdmin({ currentUserId }) {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [q, setQ] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatusFilter] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListUsers({ q, role, status })
      .then((data) => {
        if (!mounted) return;
        setUsers(data?.data || []);
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load users.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [q, role, status, refreshKey]);

  const refresh = () => setRefreshKey((k) => k + 1);

  const handleRoleChange = async (user, newRole) => {
    setError('');
    try {
      await adminUpdateUser(user.id, { role: newRole });
      refresh();
    } catch (err) {
      setError(err?.message || 'Failed to update role.');
    }
  };

  const handleToggleStatus = async (user) => {
    setError('');

    // Reactivating just flips the status back; the backend clears any stored reason.
    if (user.status === 'suspended') {
      try {
        await adminUpdateUser(user.id, { status: 'active' });
        refresh();
      } catch (err) {
        setError(err?.message || 'Failed to update status.');
      }
      return;
    }

    // Suspending: ask the admin why. Cancelling the prompt aborts the suspension.
    const reason = window.prompt(`Why are you suspending ${user.full_name}? (shown to the user when they try to log in)`, '');
    if (reason === null) return;

    try {
      await adminUpdateUser(user.id, { status: 'suspended', suspension_reason: reason.trim() });
      refresh();
    } catch (err) {
      setError(err?.message || 'Failed to update status.');
    }
  };

  return (
    <>
      <h2 className="dashSectionTitle">👥 User Management</h2>
      {error && <div className="ui-error">{error}</div>}

      <div className="dashFilterBar">
        <input className="ui-input" style={{ maxWidth: 220 }} placeholder="Search name/email" aria-label="Search users by name or email" value={q} onChange={(e) => setQ(e.target.value)} />
        <select className="ui-input" style={{ maxWidth: 150 }} aria-label="Filter users by role" value={role} onChange={(e) => setRole(e.target.value)}>
          <option value="">All roles</option>
          {ROLES.map((r) => <option key={r} value={r}>{r}</option>)}
        </select>
        <select className="ui-input" style={{ maxWidth: 150 }} aria-label="Filter users by status" value={status} onChange={(e) => setStatusFilter(e.target.value)}>
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : users.length === 0 ? (
        <div className="ui-empty">No users match these filters.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>User</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {users.map((u) => {
                const isSelf = u.id === currentUserId;
                return (
                  <tr key={u.id}>
                    <td>
                      {u.full_name}
                      {u.username && <span style={{ fontSize: 12, color: 'var(--muted)' }}> · @{u.username}</span>}
                      <br />
                      <span style={{ fontSize: 12, color: 'var(--muted)' }}>{u.email}</span>
                    </td>
                    <td>{u.phone || '—'}</td>
                    <td>
                      <select
                        className="ui-input"
                        style={{ maxWidth: 130 }}
                        value={u.role}
                        disabled={isSelf}
                        aria-label={`Change role for ${u.full_name}`}
                        onChange={(e) => handleRoleChange(u, e.target.value)}
                      >
                        {ROLES.map((r) => <option key={r} value={r}>{r}</option>)}
                      </select>
                    </td>
                    <td>
                      <StatusBadge status={u.status} />
                      {u.status === 'suspended' && u.suspension_reason && (
                        <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4, maxWidth: 220 }} title={u.suspension_reason}>
                          Reason: {u.suspension_reason}
                        </div>
                      )}
                    </td>
                    <td>
                      <button className="dashBtn" disabled={isSelf} onClick={() => handleToggleStatus(u)}>
                        {u.status === 'suspended' ? 'Reactivate' : 'Suspend'}
                      </button>
                      {isSelf && <span style={{ marginLeft: 8, fontSize: 12, color: 'var(--muted)' }}>(you)</span>}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
