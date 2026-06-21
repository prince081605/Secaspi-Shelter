import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import './Dashboard.css';
import { dashboardMock } from '../../lib/dashboardMockData';
import { auth } from '../../lib/auth';
import { listMyAdoptionApplications, listMyFosterApplications } from '../../lib/animalsApi';
import { updateProfile, changePassword } from '../../lib/profileApi';
import AnimalsAdmin from '../admin/AnimalsAdmin';
import AdoptionRequestsAdmin from '../admin/AdoptionRequestsAdmin';
import FosterRequestsAdmin from '../admin/FosterRequestsAdmin';
import RescueReportsAdmin from '../admin/RescueReportsAdmin';
import DonationsAdmin from '../admin/DonationsAdmin';
import UsersAdmin from '../admin/UsersAdmin';
import { adminGetOverview } from '../../lib/dashboardApi';
import VolunteersAdmin from '../admin/VolunteersAdmin';
import ReportsAdmin from '../admin/ReportsAdmin';
import StatusBadge from '../../components/StatusBadge';

const fallbackRole = 'user';


function safeRoleFromUser(user) {
  const role = user?.role || user?.user?.role || user?.data?.role;
  if (!role) return fallbackRole;
  return String(role).toLowerCase();
}

function OverviewCards({ cards }) {
  return (
    <div className="dashGridCards">
      {cards.map((c) => (
        <div
          key={c.key}
          className={"dashCard " + (c.variant === 'green' ? 'dashCardGreen' : c.variant)}
        >
          <div className="dashCardValue">
            {c.value}
          </div>
          <div className="dashCardLabel">{c.label}</div>
          {c.sub ? <div className="dashCardSub">{c.sub}</div> : null}
        </div>
      ))}
    </div>
  );
}


function ActivityFeed({ activity }) {
  return (
    <>
      <div className="dashSectionTitle">🕒 Recent activity</div>
      {!activity || activity.length === 0 ? (
        <div className="ui-empty">No recent activity.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr><th>Event</th><th>Status</th><th>When</th></tr>
            </thead>
            <tbody>
              {activity.map((a, idx) => (
                <tr key={idx}>
                  <td>{a.label}</td>
                  <td><StatusBadge status={a.status} /></td>
                  <td>{(a.created_at || '').toString().slice(0, 16)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}


function UserApplications({ applications, loading }) {
  return (
    <>
      <div className="dashSectionTitle">❤️ My Applications</div>
      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : applications.length === 0 ? (
        <div className="ui-empty">You haven't submitted any adoption or foster applications yet.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>Type</th>
                <th>Animal</th>
                <th>Status</th>
                <th>Submitted</th>
              </tr>
            </thead>
            <tbody>
              {applications.map((r) => (
                <tr key={`${r.type}-${r.id}`}>
                  <td>{r.type}</td>
                  <td className="dashFlexRow">
                    {r.animal?.photo ? (
                      <img
                        src={r.animal.photo.startsWith('http') ? r.animal.photo : `${import.meta.env.VITE_API_BASE_URL}/storage/${r.animal.photo}`}
                        alt={r.animal?.name}
                        className="dashThumbSm"
                      />
                    ) : null}
                    {r.animal?.name || 'Unknown animal'}
                  </td>
                  <td>
                    <StatusBadge status={r.status} />
                  </td>
                  <td>{(r.created_at || '').slice(0, 10) || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

function UserFavorites() {
  const favorites = dashboardMock.recentlyAdopted;

  return (
    <>
      <div className="dashSectionTitle">🐶 Recently adopted Aspins</div>
      <div className="dashTableWrap">
        <table className="dashTable">
          <thead>
            <tr>
              <th>Dog</th>
              <th>Adopter</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {favorites.map((f, idx) => (
              <tr key={idx}>
                <td>{f.dog}</td>
                <td>{f.adopter}</td>
                <td>
                  <span className={"badge " + (f.variant === 'green' ? 'badgeGreen' : f.variant === 'amber' ? 'badgeOrange' : f.variant === 'blue' ? 'badgeSky' : 'badge')}>{f.status}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}


function UserProfile({ user, onProfileUpdated }) {
  const [fullName, setFullName] = useState(user?.full_name || '');
  const [phone, setPhone] = useState(user?.phone || '');
  const [profileState, setProfileState] = useState({ status: 'idle', error: '' });

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [passwordState, setPasswordState] = useState({ status: 'idle', error: '' });

  const handleProfileSubmit = async (e) => {
    e.preventDefault();
    setProfileState({ status: 'loading', error: '' });
    try {
      const data = await updateProfile({ full_name: fullName, phone });
      setProfileState({ status: 'success', error: '' });
      onProfileUpdated?.(data?.user);
    } catch (err) {
      setProfileState({ status: 'error', error: err?.message || 'Failed to update profile.' });
    }
  };

  const handlePasswordSubmit = async (e) => {
    e.preventDefault();
    setPasswordState({ status: 'loading', error: '' });
    try {
      await changePassword({
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      });
      setPasswordState({ status: 'success', error: '' });
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    } catch (err) {
      setPasswordState({ status: 'error', error: err?.message || 'Failed to change password.' });
    }
  };

  return (
    <>
      <div className="dashSectionTitle">📄 Profile</div>
      <div className="dashTableWrap">
        <table className="dashTable">
          <tbody>
            <tr>
              <th style={{ width: 160 }}>Name</th>
              <td>{user?.full_name || '—'}</td>
            </tr>
            <tr>
              <th>Email</th>
              <td>{user?.email || '—'}</td>
            </tr>
            <tr>
              <th>Contact</th>
              <td>{user?.phone || '—'}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div className="dashSectionTitle">✏️ Edit profile</div>
      {profileState.status === 'success' && <div className="ui-success-msg">Profile updated.</div>}
      {profileState.status === 'error' && <div className="ui-error">{profileState.error}</div>}
      <form onSubmit={handleProfileSubmit}>
        <div className="ui-field">
          <label className="ui-label">Full name</label>
          <input className="ui-input" value={fullName} onChange={(e) => setFullName(e.target.value)} required />
        </div>
        <div className="ui-field">
          <label className="ui-label">Phone</label>
          <input className="ui-input" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="09XX XXX XXXX" />
        </div>
        <button className="ui-btn-primary" type="submit" disabled={profileState.status === 'loading'}>
          {profileState.status === 'loading' ? 'Saving…' : 'Save changes'}
        </button>
      </form>

      <div className="dashSectionTitle">🔒 Change password</div>
      {passwordState.status === 'success' && <div className="ui-success-msg">Password changed successfully.</div>}
      {passwordState.status === 'error' && <div className="ui-error">{passwordState.error}</div>}
      <form onSubmit={handlePasswordSubmit}>
        <div className="ui-field">
          <label className="ui-label">Current password</label>
          <input className="ui-input" type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} required />
        </div>
        <div className="ui-field">
          <label className="ui-label">New password</label>
          <input className="ui-input" type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} required minLength={8} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Confirm new password</label>
          <input className="ui-input" type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} required minLength={8} />
        </div>
        <button className="ui-btn-primary" type="submit" disabled={passwordState.status === 'loading'}>
          {passwordState.status === 'loading' ? 'Updating…' : 'Change password'}
        </button>
      </form>
    </>
  );
}

function UserMessages() {
  const reminders = dashboardMock.medicalReminders;

  return (
    <>
      <div className="dashSectionTitle">
        💉 Upcoming medical reminders
      </div>
      <div className="dashTableWrap">
        <table className="dashTable">
          <thead>
            <tr>
              <th>Dog</th>
              <th>ID</th>
              <th>Type</th>
              <th>Due date</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {reminders.map((r, idx) => (
              <tr key={idx}>
                <td>{r.dog}</td>
                <td>{r.id}</td>
                <td>{r.type}</td>
                <td>{r.dueDate}</td>
                <td>
                  <span className={"badge " + (r.variant === 'green' ? 'badgeGreen' : r.variant === 'red' ? 'badgeOrange' : 'badgeOrange')}>{r.status}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}


export default function Dashboard() {
  const navigate = useNavigate();
  const [role, setRole] = useState('');
  const [tab, setTab] = useState('user');
  const [user, setUser] = useState(null);
  const [applications, setApplications] = useState([]);
  const [appsLoading, setAppsLoading] = useState(true);
  const [overview, setOverview] = useState(null);

  // keep empty when data isn't available
  const isAdminRole = role === 'admin';

  const handleLogout = async () => {
    try {
      await auth.logout();
    } finally {
      navigate('/login', { replace: true });
    }
  };

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await auth.me();
        if (!mounted) return;
        const u = data?.user || data;
        setUser(u);
        const r = safeRoleFromUser(data);
        setRole(r);
        setTab(r === 'admin' ? 'admin' : 'user');
      } catch {
        if (!mounted) return;
        setRole(fallbackRole);
        setTab('user');
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const [adoptions, fosters] = await Promise.all([
          listMyAdoptionApplications(),
          listMyFosterApplications(),
        ]);
        if (!mounted) return;
        const merged = [
          ...(adoptions?.applications || []).map((a) => ({ ...a, type: 'Adoption' })),
          ...(fosters?.applications || []).map((a) => ({ ...a, type: 'Foster' })),
        ].sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
        setApplications(merged);
      } catch {
        if (!mounted) return;
        setApplications([]);
      } finally {
        if (mounted) setAppsLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    if (!isAdminRole) return;
    let mounted = true;
    adminGetOverview()
      .then((data) => {
        if (mounted) setOverview(data);
      })
      .catch(() => {
        if (mounted) setOverview(null);
      });
    return () => {
      mounted = false;
    };
  }, [isAdminRole]);

  const userStats = useMemo(() => {
    const total = applications.length;
    const approved = applications.filter((a) => ['approved', 'active', 'completed'].includes(a.status)).length;
    const pending = applications.filter((a) => a.status === 'pending').length;
    const rejected = applications.filter((a) => ['rejected', 'declined'].includes(a.status)).length;

    return [
      { key: 'myApplications', variant: 'purple', label: 'My applications', value: total, sub: 'Total requests' },
      { key: 'approved', variant: 'green', label: 'Approved', value: approved, sub: 'Ready to proceed' },
      { key: 'pending', variant: 'sky', label: 'Pending', value: pending, sub: 'Waiting for approval' },
      { key: 'rejected', variant: 'orange', label: 'Rejected', value: rejected, sub: 'Try again later' },
    ];
  }, [applications]);

  const dashboardTabs = useMemo(() => {
    return [
      { key: 'admin', label: 'Admin Dashboard', show: isAdminRole },
      { key: 'user', label: 'User Dashboard', show: true },
    ].filter((t) => t.show);
  }, [isAdminRole]);

  const activeTab = tab;

  const navItems = [
    { key: 'dashboard', label: 'Dashboard', icon: '🏠' },
    { key: 'animals', label: 'Animals', icon: '🐶', show: isAdminRole },
    { key: 'requests', label: 'Adoption Requests', icon: '📩', show: isAdminRole },
    { key: 'fosters', label: 'Foster Requests', icon: '🏡', show: isAdminRole },
    { key: 'rescues', label: 'Rescue Reports', icon: '🚨', show: isAdminRole },
    { key: 'donations', label: 'Donations', icon: '💰', show: isAdminRole },
    { key: 'volunteers', label: 'Volunteers', icon: '🤝', show: isAdminRole },
    { key: 'users', label: 'Users', icon: '👥', show: isAdminRole },
    { key: 'reports', label: 'Reports', icon: '📈', show: isAdminRole },
    { key: 'settings', label: 'Settings', icon: '⚙️', show: isAdminRole },
  ].filter((i) => i.show);

  const [activeNav, setActiveNav] = useState('dashboard');

  return (
    <div className="dashboardPage">
      <div className="dashboardLayout">
        <aside className="dashSidebar">
          <div className="dashBrand">
            <button
              type="button"
              onClick={() => navigate('/')}
              style={{ display: 'flex', alignItems: 'center', gap: 10, background: 'none', border: 'none', padding: 0, cursor: 'pointer', textAlign: 'left' }}
              aria-label="Back to landing page"
            >
              <div className="dashLogo" aria-hidden="true" />
              <div>
                <div className="dashBrandTitle">SECASPI</div>
                <div className="dashBrandTitle" style={{ fontSize: 12, color: 'var(--muted)' }}>
                  Shelter Admin
                </div>
              </div>
            </button>
            <div className="dashRoleChip">{role ? `Role: ${role}` : 'Role: —'}</div>
          </div>

          <nav className="dashNav">
            <button className="dashNavBtn" onClick={() => navigate('/')}>
              ⬅️ Back to Home
            </button>
            {navItems.map((item) => (
              <button
                key={item.key}
                className={
                  'dashNavBtn ' + (activeNav === item.key ? 'dashNavBtnActive' : '')
                }
                onClick={() => setActiveNav(item.key)}
              >
                {item.icon} {item.label}
              </button>
            ))}
            {!isAdminRole ? (
              <button
                className={'dashNavBtn ' + (activeNav === 'profile' ? 'dashNavBtnActive' : '')}
                onClick={() => setActiveNav('profile')}
              >
                👤 Profile
              </button>
            ) : null}
            <button className="dashNavBtn" onClick={handleLogout}>
              🚪 Logout
            </button>
          </nav>
        </aside>

        <main className="dashMain">
          <div className="dashTopRow">
            <div>
              <h1 className="dashTitle">Control Center</h1>
              <div className="dashSubtitle">
                {activeTab === 'admin'
                  ? 'Manage animals, requests, users, and analytics.'
                  : 'Manage your adoption applications and favorites.'}
              </div>
            </div>

            <div className="dashTabs">
              {dashboardTabs.map((t) => (
                <button
                  key={t.key}
                  className={"dashTab " + (activeTab === t.key ? 'dashTabActive' : '')}
                  onClick={() => setTab(t.key)}
                >
                  {t.label}
                </button>
              ))}
            </div>
          </div>

          <OverviewCards cards={activeTab === 'admin' ? (overview?.stats || []) : userStats} />

          {activeTab === 'admin' ? (
            <div>
              {activeNav === 'dashboard' ? <ActivityFeed activity={overview?.activity} /> : null}
              {activeNav === 'animals' ? <AnimalsAdmin /> : null}
              {activeNav === 'requests' ? <AdoptionRequestsAdmin /> : null}
              {activeNav === 'fosters' ? <FosterRequestsAdmin /> : null}
              {activeNav === 'rescues' ? <RescueReportsAdmin /> : null}
              {activeNav === 'donations' ? <DonationsAdmin /> : null}
              {activeNav === 'volunteers' ? <VolunteersAdmin /> : null}
              {activeNav === 'reports' ? <ReportsAdmin /> : null}
              {activeNav === 'users' ? <UsersAdmin currentUserId={user?.id} /> : null}
              {activeNav === 'settings' ? <UserProfile key={user?.id} user={user} onProfileUpdated={setUser} /> : null}
            </div>
          ) : (
            <div>
              {activeNav === 'dashboard' ? <UserApplications applications={applications} loading={appsLoading} /> : null}
              {activeNav === 'animals' ? <UserFavorites /> : null}

              {activeNav === 'profile' ? <UserProfile key={user?.id} user={user} onProfileUpdated={setUser} /> : null}
              {activeNav === 'reports' ? <UserMessages /> : null}
              {/* default user sections */}
              {activeNav === 'dashboard' ? <UserFavorites /> : null}
              {activeNav === 'dashboard' ? <UserProfile key={user?.id} user={user} onProfileUpdated={setUser} /> : null}
              {activeNav === 'dashboard' ? <UserMessages /> : null}

            </div>
          )}
        </main>
      </div>
    </div>
  );
}

