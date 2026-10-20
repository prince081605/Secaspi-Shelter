import { useEffect, useState } from 'react';
import { adminListUsers, adminUpdateUser } from '../../lib/usersApi';
import { Users } from 'lucide-react';
import StatusBadge from '../../components/StatusBadge';
import useConfirm from '../../lib/useConfirm';
import DashCard from '../../components/DashCard';
import useIsMobile from '../../lib/useIsMobile';
import { ADMIN_ROLE_SUMMARIES, ROLES, isAdminRole, roleLabel } from '../../lib/permissions';

const STATUSES = ['active', 'suspended', 'pending'];

// What the person will be able to do after the change, in the confirm dialog. There's no role
// with full access: each admin type is limited to its own area.
function roleChangeMessage(newRole) {
  if (isAdminRole(newRole)) return `As a ${roleLabel(newRole)}: ${ADMIN_ROLE_SUMMARIES[newRole]}`;
  if (newRole === 'staff') return 'Staff run day-to-day shelter work and see the operational reports. They are added to Personnel.';
  if (newRole === 'volunteer') return 'Volunteers get tasks and attendance on their dashboard. They are added to Personnel.';
  return 'A plain account: their own adoptions, donations and messages.';
}

export default function UsersAdmin({ currentUserId }) {
  const confirm = useConfirm();
  const isMobile = useIsMobile();
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

  // `selectEl` is the <select> that fired this. It is controlled by server state, so when the
  // admin cancels there is no state change for React to re-render from and the dropdown would
  // sit there showing a role the user does not actually have. Put it back by hand.
  const handleRoleChange = async (user, newRole, selectEl) => {
    const ok = await confirm({
      title: `Change ${user.full_name}’s role?`,
      message: `${roleChangeMessage(newRole)} It applies the next time they load the dashboard.`,
      confirmLabel: 'Change role',
      tone: newRole === 'system_admin' ? 'danger' : 'default',
      summary: [
        { label: 'User', value: user.full_name },
        { label: 'From', value: roleLabel(user.role) },
        { label: 'To', value: roleLabel(newRole) },
      ],
    });
    if (!ok) {
      if (selectEl) selectEl.value = user.role;
      return;
    }
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
      const ok = await confirm({
        title: `Reactivate ${user.full_name}?`,
        message: 'They can log in again, and the suspension reason on their account is cleared.',
        confirmLabel: 'Reactivate',
        summary: [{ label: 'User', value: user.full_name }, { label: 'Email', value: user.email }],
      });
      if (!ok) return;
      try {
        await adminUpdateUser(user.id, { status: 'active' });
        refresh();
      } catch (err) {
        setError(err?.message || 'Failed to update status.');
      }
      return;
    }

    const ok = await confirm({
      title: `Suspend ${user.full_name}?`,
      message: 'They are locked out of their account until a System Admin reactivates them. You will be asked for a reason next.',
      confirmLabel: 'Suspend account',
      tone: 'danger',
      summary: [{ label: 'User', value: user.full_name }, { label: 'Email', value: user.email }],
    });
    if (!ok) return;

    // Suspending: ask why. Cancelling the prompt aborts the suspension.
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
      <h2 className="dashSectionTitle"><Users size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />User Management</h2>
      {error && <div className="ui-error">{error}</div>}

      <div className="dashFilterBar">
        <input className="ui-input" style={{ maxWidth: 220 }} placeholder="Search name/email" aria-label="Search users by name or email" value={q} onChange={(e) => setQ(e.target.value)} />
        <select className="ui-input" style={{ maxWidth: 170 }} aria-label="Filter users by role" value={role} onChange={(e) => setRole(e.target.value)}>
          <option value="">All roles</option>
          {ROLES.map((r) => <option key={r} value={r}>{roleLabel(r)}</option>)}
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
      ) : isMobile ? (
        <div className="dashCardList">
          {users.map((u) => {
            const isSelf = u.id === currentUserId;
            const roleSelect = (
              <select
                className="ui-input"
                value={u.role}
                disabled={isSelf}
                aria-label={`Change role for ${u.full_name}`}
                onChange={(e) => handleRoleChange(u, e.target.value, e.target)}
              >
                {ROLES.map((r) => <option key={r} value={r}>{roleLabel(r)}</option>)}
              </select>
            );
            const statusVal = (
              <>
                <StatusBadge status={u.status} />
                {u.status === 'suspended' && u.suspension_reason && (
                  <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }} title={u.suspension_reason}>
                    Reason: {u.suspension_reason}
                  </div>
                )}
              </>
            );
            return (
              <DashCard
                key={u.id}
                title={<>{u.full_name}{u.username && <span style={{ fontSize: 12, color: 'var(--muted)' }}> · @{u.username}</span>}</>}
                subtitle={u.email}
                fields={[
                  { label: 'Phone', value: u.phone || '—' },
                  { label: 'Role', value: roleSelect },
                  { label: 'Status', value: statusVal },
                ]}
                actions={
                  <>
                    <button className="dashBtn" disabled={isSelf} onClick={() => handleToggleStatus(u)}>
                      {u.status === 'suspended' ? 'Reactivate' : 'Suspend'}
                    </button>
                    {isSelf && <span style={{ alignSelf: 'center', fontSize: 12, color: 'var(--muted)' }}>(you)</span>}
                  </>
                }
              />
            );
          })}
        </div>
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
                        style={{ minWidth: 135, maxWidth: 160 }}
                        value={u.role}
                        disabled={isSelf}
                        aria-label={`Change role for ${u.full_name}`}
                        onChange={(e) => handleRoleChange(u, e.target.value, e.target)}
                      >
                        {ROLES.map((r) => <option key={r} value={r}>{roleLabel(r)}</option>)}
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
