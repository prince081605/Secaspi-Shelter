import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import './Dashboard.css';
import { auth } from '../../lib/auth';
import { listMyAdoptionApplications, listMyFosterApplications } from '../../lib/animalsApi';
import { updateProfile, changePassword } from '../../lib/profileApi';
import Reveal from '../../components/Reveal';
import PasswordInput from '../../components/PasswordInput';
import {
  Clock, Heart, User, Pencil, Lock, ClipboardList, Dog, Trophy, LayoutDashboard,
  ArrowLeft, Menu, X, LogOut, MessageSquare, PawPrint, Bell, Inbox, HeartHandshake,
  Siren, Calendar, Wrench, HandCoins, BarChart3, Users, UsersRound, Settings,
  ChevronRight, Receipt, Tags, House,
} from 'lucide-react';
import AnimalsAdmin from '../admin/AnimalsAdmin';
import AdoptionRequestsAdmin from '../admin/AdoptionRequestsAdmin';
import RescueReportsAdmin from '../admin/RescueReportsAdmin';
import DonationsAdmin from '../admin/DonationsAdmin';
import ExpensesAdmin from '../admin/ExpensesAdmin';
import UsersAdmin from '../admin/UsersAdmin';
import { adminGetOverview, adminGetPendingCounts } from '../../lib/dashboardApi';
import { getMyVolunteer } from '../../lib/volunteersApi';
import VolunteersAdmin from '../admin/VolunteersAdmin';
import MyTaskItem, { MyTaskList, RequestTaskForm } from '../../components/MyTaskItem';
import AttendanceCard from '../../components/AttendanceCard';
import ReportsAdmin from '../admin/ReportsAdmin';
import AnalyticsAdmin from '../admin/AnalyticsAdmin';
import Messages from '../Messages';
import ImpactPanel from './ImpactPanel';
import MyDonationsPanel from './MyDonationsPanel';
import SettingsAdmin from '../admin/SettingsAdmin';
import CategoriesAdmin from '../admin/CategoriesAdmin';
import PostAdoptionAdmin from '../admin/PostAdoptionAdmin';
import MyAdoptionsPanel from './MyAdoptionsPanel';
import VisitationsAdmin from '../admin/VisitationsAdmin';
import RemindersAdmin from '../admin/RemindersAdmin';
import StatusBadge from '../../components/StatusBadge';
import NotificationBell from '../../components/NotificationBell';
import DashCard from '../../components/DashCard';
import useIsMobile from '../../lib/useIsMobile';

const fallbackRole = 'user';

// Which collapsible sidebar category each admin nav item belongs to. Used to
// auto-expand the category that contains the active item.
const ITEM_CATEGORY = {
  animals: 'cat_animals', reminders: 'cat_animals',
  requests: 'cat_requests', rescues: 'cat_requests', visitations: 'cat_requests', messages: 'cat_requests',
  postadoption: 'cat_requests',
  donations: 'cat_ops', expenses: 'cat_ops', reports: 'cat_ops', users: 'cat_ops', settings: 'cat_ops', volunteers: 'cat_ops',
  categories: 'cat_ops',
};
const NAV_CATEGORY_KEYS = ['cat_animals', 'cat_requests', 'cat_ops'];

// Role hierarchy (mirrors backend App\Models\User::ROLE_RANKS). Access is by minimum
// rank: a higher role clears every gate a lower one can.
const ROLE_RANK = { user: 1, volunteer: 2, staff: 3, admin: 4 };
const rankOf = (r) => ROLE_RANK[r] || 0;
const atLeast = (r, min) => rankOf(r) >= rankOf(min);

// Minimum role required to see each admin nav item. Staff run operations; Users and
// Settings stay admin-only. Items missing here default to admin (fail closed).
const ITEM_MIN_ROLE = {
  animals: 'staff', reminders: 'staff',
  requests: 'staff', rescues: 'staff', visitations: 'staff', messages: 'staff', postadoption: 'staff',
  // Staff can read the expense ledger; writing to it is admin-only and gated inside the panel
  // (and on the server), the same split as Donations.
  donations: 'staff', expenses: 'staff', reports: 'staff', volunteers: 'staff',
  users: 'admin', settings: 'admin', categories: 'admin',
};


function safeRoleFromUser(user) {
  const role = user?.role || user?.user?.role || user?.data?.role;
  if (!role) return fallbackRole;
  return String(role).toLowerCase();
}

function OverviewCards({ cards }) {
  // One observer for the whole row; each card's --i staggers it 100ms behind the last.
  return (
    <Reveal variant="group" className="dashGridCards">
      {cards.map((c, i) => (
        <div
          key={c.key}
          style={{ '--i': i }}
          className={"dashCard ui-reveal-item " + (c.variant === 'green' ? 'dashCardGreen' : c.variant)}
        >
          <div className="dashCardValue">
            {c.value}
          </div>
          <div className="dashCardLabel">{c.label}</div>
          {c.sub ? <div className="dashCardSub">{c.sub}</div> : null}
        </div>
      ))}
    </Reveal>
  );
}


function ActivityFeed({ activity }) {
  const isMobile = useIsMobile();
  return (
    <>
      <h2 className="dashSectionTitle"><Clock size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Recent activity</h2>
      {!activity || activity.length === 0 ? (
        <div className="ui-empty">No recent activity.</div>
      ) : isMobile ? (
        <div className="dashCardList">
          {activity.map((a, idx) => (
            <DashCard
              key={idx}
              title={a.label}
              fields={[
                { label: 'Status', value: <StatusBadge status={a.status} /> },
                { label: 'When', value: (a.created_at || '').toString().slice(0, 16) },
              ]}
            />
          ))}
        </div>
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
  const isMobile = useIsMobile();
  const photo = (r) => (r.animal?.photo ? (
    <img
      src={r.animal.photo.startsWith('http') ? r.animal.photo : `${import.meta.env.VITE_API_BASE_URL}/storage/${r.animal.photo}`}
      alt=""
      className="dashThumbSm"
    />
  ) : null);
  return (
    <>
      <h2 className="dashSectionTitle"><Heart size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />My Applications</h2>
      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : applications.length === 0 ? (
        <div className="ui-empty">You haven't submitted any adoption or foster applications yet.</div>
      ) : isMobile ? (
        <div className="dashCardList">
          {applications.map((r) => (
            <DashCard
              key={`${r.type}-${r.id}`}
              media={photo(r)}
              title={r.animal?.name || 'Unknown animal'}
              subtitle={r.type}
              fields={[
                { label: 'Status', value: <StatusBadge status={r.status} /> },
                { label: 'Submitted', value: (r.created_at || '').slice(0, 10) || '—' },
              ]}
            />
          ))}
        </div>
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
                  <td>
                    <div className="dashFlexRow">
                      {r.animal?.photo ? (
                        <img
                          src={r.animal.photo.startsWith('http') ? r.animal.photo : `${import.meta.env.VITE_API_BASE_URL}/storage/${r.animal.photo}`}
                          alt={r.animal?.name}
                          className="dashThumbSm"
                        />
                      ) : null}
                      {r.animal?.name || 'Unknown animal'}
                    </div>
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

function UserProfile({ user, onProfileUpdated }) {
  const isMobile = useIsMobile();
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
      <h2 className="dashSectionTitle"><User size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Profile</h2>
      {isMobile ? (
        <DashCard
          fields={[
            { label: 'Name', value: user?.full_name || '—' },
            { label: 'Email', value: user?.email || '—' },
            { label: 'Contact', value: user?.phone || '—' },
          ]}
        />
      ) : (
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
      )}

      <h2 className="dashSectionTitle"><Pencil size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Edit profile</h2>
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

      <h2 className="dashSectionTitle"><Lock size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Change password</h2>
      {passwordState.status === 'success' && <div className="ui-success-msg">Password changed successfully.</div>}
      {passwordState.status === 'error' && <div className="ui-error">{passwordState.error}</div>}
      <form onSubmit={handlePasswordSubmit}>
        <div className="ui-field">
          <label className="ui-label">Current password</label>
          <PasswordInput value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} autoComplete="current-password" required />
        </div>
        <div className="ui-field">
          <label className="ui-label">New password</label>
          <PasswordInput value={newPassword} onChange={(e) => setNewPassword(e.target.value)} autoComplete="new-password" required minLength={8} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Confirm new password</label>
          <PasswordInput value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} autoComplete="new-password" required minLength={8} />
        </div>
        <button className="ui-btn-primary" type="submit" disabled={passwordState.status === 'loading'}>
          {passwordState.status === 'loading' ? 'Updating…' : 'Change password'}
        </button>
      </form>
    </>
  );
}

// A volunteer's or staff member's own task hub: their time clock (Time in / Time out), their
// tasks, each with an Update button for sending proof of completion, plus a request-a-task form. Same endpoints as the public
// VolunteerApply page (GET /volunteer/me, POST /volunteer/tasks, POST .../proof).
function VolunteerTasksPanel() {
  const [loading, setLoading] = useState(true);
  const [volunteer, setVolunteer] = useState(null);

  // Refreshes after the first load stay quiet: flipping `loading` would unmount the task list
  // and close the task the person just updated, hiding its new completion details.
  const load = () => {
    getMyVolunteer()
      .then((v) => setVolunteer(v?.volunteer || null))
      .catch(() => setVolunteer(null))
      .finally(() => setLoading(false));
  };
  useEffect(() => { load(); }, []);

  if (loading) return <div className="ui-empty">Loading…</div>;
  if (!volunteer) {
    return (
      <>
        <h2 className="dashSectionTitle"><ClipboardList size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />My Tasks</h2>
        <div className="ui-empty">Your personnel profile isn't set up yet. Please contact the shelter team.</div>
      </>
    );
  }

  return (
    <>
      <h2 className="dashSectionTitle"><ClipboardList size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />My Tasks</h2>
      <AttendanceCard />
      <RequestTaskForm onRequested={load} />
      {(!volunteer.tasks || volunteer.tasks.length === 0) ? (
        <div className="ui-empty">No tasks yet. Request one above to get started!</div>
      ) : (
        <MyTaskList>
          {volunteer.tasks.map((t) => (
            <MyTaskItem key={t.id} task={t} onUpdated={load} />
          ))}
        </MyTaskList>
      )}
    </>
  );
}

export default function Dashboard() {
  const navigate = useNavigate();
  // Other pages can open a specific user-dashboard module, e.g. /donations redirects here with
  // { nav: 'mydonations' } so links to the old standalone page land on the module instead.
  const requestedNav = useLocation().state?.nav;
  const [role, setRole] = useState('');
  const [tab, setTab] = useState('user');
  const [user, setUser] = useState(null);
  const [applications, setApplications] = useState([]);
  const [appsLoading, setAppsLoading] = useState(true);
  const [overview, setOverview] = useState(null);
  const [pendingRescueCount, setPendingRescueCount] = useState(0);
  const [pendingAdoptionCount, setPendingAdoptionCount] = useState(0);
  const [pendingPostAdoptionCount, setPendingPostAdoptionCount] = useState(0);
  const [pendingFosterCount, setPendingFosterCount] = useState(0);
  const [pendingDonationCount, setPendingDonationCount] = useState(0);
  const [pendingVisitationCount, setPendingVisitationCount] = useState(0);
  const [overdueReminderCount, setOverdueReminderCount] = useState(0);
  const [pendingVolunteerCount, setPendingVolunteerCount] = useState(0);

  // keep empty when data isn't available
  const isAdminRole = role === 'admin';
  const isStaffPlus = atLeast(role, 'staff'); // staff + admin: operational dashboard
  const isVolunteer = role === 'volunteer';   // normal user dashboard + an extra "My Tasks" module
  const isStaff = role === 'staff';           // staff dashboard + their own "My Tasks" module

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
        // Land each role on its primary dashboard: staff/admin → operations, everyone else
        // (users and volunteers) → the user dashboard.
        setTab(atLeast(r, 'staff') && !requestedNav ? 'admin' : 'user');
      } catch {
        if (!mounted) return;
        setRole(fallbackRole);
        setTab('user');
      }
    })();
    return () => {
      mounted = false;
    };
  }, [requestedNav]);

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
    if (!isStaffPlus) return;
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
  }, [isStaffPlus]);

  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => { mountedRef.current = false; };
  }, []);

  const fetchPendingCounts = useCallback(() => {
    if (!isStaffPlus) return;
    // One aggregated request instead of 7 separate polls every 30s (audit §11).
    adminGetPendingCounts()
      .then((data) => {
        if (!mountedRef.current) return;
        setPendingRescueCount(data?.rescue || 0);
        setPendingAdoptionCount(data?.adoption || 0);
        setPendingFosterCount(data?.foster || 0);
        setPendingDonationCount(data?.donation || 0);
        setPendingVisitationCount(data?.visitation || 0);
        setOverdueReminderCount(data?.reminders_overdue || 0);
        setPendingVolunteerCount(data?.volunteer || 0);
        setPendingPostAdoptionCount(data?.post_adoption || 0);
      })
      .catch(() => { /* leave counts unchanged on a transient failure */ });
  }, [isStaffPlus]);

  useEffect(() => {
    if (!isStaffPlus) return;
    fetchPendingCounts();
    const interval = setInterval(fetchPendingCounts, 30000);
    return () => clearInterval(interval);
  }, [isStaffPlus, fetchPendingCounts]);

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
    // Volunteers use the normal user dashboard (plus a My Tasks nav item), so there's no
    // separate volunteer tab — only staff/admin get an extra tab above the user one.
    return [
      { key: 'admin', label: isAdminRole ? 'Admin Dashboard' : 'Staff Dashboard', show: isStaffPlus },
      { key: 'user', label: 'User Dashboard', show: true },
    ].filter((t) => t.show);
  }, [isAdminRole, isStaffPlus]);

  const activeTab = tab;

  const navCategories = [
    {
      key: 'cat_animals', label: 'Animal Care', icon: PawPrint,
      items: [
        { key: 'animals', label: 'Animals', icon: Dog },
        { key: 'reminders', label: 'Health Reminders', icon: Bell, badge: overdueReminderCount },
      ],
    },
    {
      key: 'cat_requests', label: 'Requests', icon: Inbox,
      items: [
        { key: 'requests', label: 'Adoption & Foster', icon: HeartHandshake, badge: pendingAdoptionCount + pendingFosterCount },
        { key: 'postadoption', label: 'Post-adoption', icon: House, badge: pendingPostAdoptionCount },
        { key: 'rescues', label: 'Rescue Reports', icon: Siren, badge: pendingRescueCount },
        { key: 'visitations', label: 'Visit Requests', icon: Calendar, badge: pendingVisitationCount },
        { key: 'messages', label: 'Messages', icon: MessageSquare },
      ],
    },
    {
      key: 'cat_ops', label: 'Operations', icon: Wrench,
      items: [
        { key: 'donations', label: 'Donations', icon: HandCoins, badge: pendingDonationCount },
        { key: 'expenses', label: 'Expenses', icon: Receipt },
        { key: 'reports', label: 'Reports', icon: BarChart3 },
        { key: 'users', label: 'Users', icon: Users },
        { key: 'volunteers', label: 'Personnel', icon: UsersRound, badge: pendingVolunteerCount },
        { key: 'categories', label: 'Categories', icon: Tags },
        { key: 'settings', label: 'Settings', icon: Settings },
      ],
    },
  ];

  // Hide nav items above the current role (e.g. staff never see Users/Settings), then
  // drop any category left empty. Admin sees everything.
  const visibleNavCategories = navCategories
    .map((cat) => ({
      ...cat,
      items: cat.items.filter((it) => atLeast(role, ITEM_MIN_ROLE[it.key] || 'admin')),
    }))
    .filter((cat) => cat.items.length > 0);

  const [activeNav, setActiveNav] = useState(requestedNav || 'dashboard');
  // On phones the sidebar collapses behind a ☰ toggle; selecting a nav item closes it (see effect
  // below) so the chosen panel is shown instead of the long nav list.
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [openCategories, setOpenCategories] = useState(() => {
    const allOpen = Object.fromEntries(NAV_CATEGORY_KEYS.map((k) => [k, true]));
    try {
      const saved = JSON.parse(localStorage.getItem('secaspi_admin_nav_open') || '{}');
      return { ...allOpen, ...saved };
    } catch {
      return allOpen;
    }
  });

  const toggleCategory = (key) => {
    setOpenCategories((prev) => {
      const next = { ...prev, [key]: !prev[key] };
      try { localStorage.setItem('secaspi_admin_nav_open', JSON.stringify(next)); } catch { /* ignore */ }
      return next;
    });
  };

  // Keep the active item's category expanded so it's never hidden behind a collapsed header.
  useEffect(() => {
    const cat = ITEM_CATEGORY[activeNav];
    if (cat) setOpenCategories((prev) => (prev[cat] ? prev : { ...prev, [cat]: true }));
    // Selecting a destination collapses the mobile nav so the panel is visible immediately.
    setMobileNavOpen(false);
  }, [activeNav]);

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
            <div className="dashBrandRight">
              <div className="dashRoleChip">{role ? `Role: ${role}` : 'Role: —'}</div>
              <button
                type="button"
                className="dashMobileNavToggle"
                onClick={() => setMobileNavOpen((v) => !v)}
                aria-label="Toggle navigation menu"
                aria-expanded={mobileNavOpen}
              >
                {mobileNavOpen ? <X size={20} /> : <Menu size={20} />}
              </button>
            </div>
          </div>

          <nav className={'dashNav' + (mobileNavOpen ? ' dashNavMobileOpen' : '')}>
            <button className="dashNavBtn" onClick={() => navigate('/')}>
              <ArrowLeft size={16} style={{ verticalAlign: '-3px' }} /> Back to Home
            </button>

            <button
              className={'dashNavBtn ' + (activeNav === 'dashboard' ? 'dashNavBtnActive' : '')}
              onClick={() => setActiveNav('dashboard')}
            >
              <LayoutDashboard size={16} style={{ verticalAlign: '-3px' }} /> Dashboard
            </button>

            {/* Staff are assigned tasks too, and report them done the same way volunteers do. */}
            {activeTab === 'admin' && isStaff && (
              <button
                className={'dashNavBtn ' + (activeNav === 'mytasks' ? 'dashNavBtnActive' : '')}
                onClick={() => setActiveNav('mytasks')}
              >
                <ClipboardList size={16} style={{ verticalAlign: '-3px' }} /> My Tasks
              </button>
            )}

            {activeTab === 'admin' && visibleNavCategories.map((cat) => {
              const isOpen = !!openCategories[cat.key];
              const aggregate = cat.items.reduce((sum, it) => sum + (it.badge || 0), 0);
              return (
                <div key={cat.key}>
                  <button
                    className="dashNavCategory"
                    onClick={() => toggleCategory(cat.key)}
                    aria-expanded={isOpen}
                  >
                    <cat.icon size={16} aria-hidden="true" />
                    <span className="dashNavCategoryLabel">{cat.label}</span>
                    {!isOpen && aggregate > 0 ? (
                      <span className="dashNavBadge">{aggregate > 99 ? '99+' : aggregate}</span>
                    ) : null}
                    <span className={'dashNavChevron' + (isOpen ? ' isOpen' : '')} aria-hidden="true">
                      <ChevronRight size={14} />
                    </span>
                  </button>
                  {isOpen && (
                    <div className="dashNavGroup">
                      {cat.items.map((item) => (
                        <button
                          key={item.key}
                          className={'dashNavBtn ' + (activeNav === item.key ? 'dashNavBtnActive' : '')}
                          onClick={() => setActiveNav(item.key)}
                        >
                          <item.icon size={16} style={{ verticalAlign: '-3px' }} /> {item.label}
                          {item.badge > 0 ? <span className="dashNavBadge">{item.badge > 99 ? '99+' : item.badge}</span> : null}
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              );
            })}

            {activeTab === 'user' && (
              <>
                {isVolunteer && (
                  <button
                    className={'dashNavBtn ' + (activeNav === 'mytasks' ? 'dashNavBtnActive' : '')}
                    onClick={() => setActiveNav('mytasks')}
                  >
                    <ClipboardList size={16} style={{ verticalAlign: '-3px' }} /> My Tasks
                  </button>
                )}
                <button
                  className={'dashNavBtn ' + (activeNav === 'myadoptions' ? 'dashNavBtnActive' : '')}
                  onClick={() => setActiveNav('myadoptions')}
                >
                  <HeartHandshake size={16} style={{ verticalAlign: '-3px' }} /> My Adopted Pets
                </button>
                <button
                  className={'dashNavBtn ' + (activeNav === 'impact' ? 'dashNavBtnActive' : '')}
                  onClick={() => setActiveNav('impact')}
                >
                  <Trophy size={16} style={{ verticalAlign: '-3px' }} /> My Impact
                </button>
                <button
                  className={'dashNavBtn ' + (activeNav === 'mydonations' ? 'dashNavBtnActive' : '')}
                  onClick={() => setActiveNav('mydonations')}
                >
                  <HandCoins size={16} style={{ verticalAlign: '-3px' }} /> My Donations
                </button>
                <button
                  className={'dashNavBtn ' + (activeNav === 'messages' ? 'dashNavBtnActive' : '')}
                  onClick={() => setActiveNav('messages')}
                >
                  <MessageSquare size={16} style={{ verticalAlign: '-3px' }} /> Messages
                </button>
                <button
                  className={'dashNavBtn ' + (activeNav === 'profile' ? 'dashNavBtnActive' : '')}
                  onClick={() => setActiveNav('profile')}
                >
                  <User size={16} style={{ verticalAlign: '-3px' }} /> Profile
                </button>
              </>
            )}
            <button className="dashNavBtn" onClick={handleLogout}>
              <LogOut size={16} style={{ verticalAlign: '-3px' }} /> Logout
            </button>
          </nav>
        </aside>

        <main className="dashMain">
          <div className="dashTopRow">
            <div>
              <h1 className="dashTitle">Control Center</h1>
              <div className="dashSubtitle">
                {activeTab === 'admin'
                  ? 'Manage animals, requests, donations, and operations.'
                  : 'Manage your adoption applications and favorites.'}
              </div>
            </div>

            <div className="dashTopRowActions">
              <div className="dashTabs">
                {dashboardTabs.map((t) => (
                  <button
                    key={t.key}
                    className={"dashTab " + (activeTab === t.key ? 'dashTabActive' : '')}
                    onClick={() => { setTab(t.key); setActiveNav('dashboard'); }}
                  >
                    {t.label}
                  </button>
                ))}
              </div>
              <NotificationBell />
            </div>
          </div>

          {/* Overview stat cards belong to the dashboard landing only — inside a module they're
              redundant, so they aren't rendered there (any screen size). */}
          {activeNav === 'dashboard' && (
            <OverviewCards cards={activeTab === 'admin' ? (overview?.stats || []) : userStats} />
          )}

          {activeTab === 'admin' ? (
            <div>
              {activeNav === 'dashboard' ? (
                <>
                  <AnalyticsAdmin />
                  <ActivityFeed activity={overview?.activity} />
                </>
              ) : null}
              {activeNav === 'animals' ? <AnimalsAdmin isAdmin={isAdminRole} /> : null}
              {activeNav === 'requests' ? <AdoptionRequestsAdmin onUnreadChanged={fetchPendingCounts} /> : null}
              {activeNav === 'postadoption' ? <PostAdoptionAdmin onChanged={fetchPendingCounts} /> : null}
              {activeNav === 'rescues' ? <RescueReportsAdmin onUnreadChanged={fetchPendingCounts} /> : null}
              {activeNav === 'visitations' ? <VisitationsAdmin /> : null}
              {activeNav === 'messages' ? <Messages staff /> : null}
              {activeNav === 'reminders' ? <RemindersAdmin onChanged={fetchPendingCounts} /> : null}
              {activeNav === 'donations' ? <DonationsAdmin isAdmin={isAdminRole} /> : null}
              {activeNav === 'expenses' ? <ExpensesAdmin isAdmin={isAdminRole} /> : null}
              {activeNav === 'volunteers' ? <VolunteersAdmin isAdmin={isAdminRole} /> : null}
              {activeNav === 'mytasks' && isStaff ? <VolunteerTasksPanel /> : null}
              {activeNav === 'reports' ? <ReportsAdmin isAdmin={isAdminRole} /> : null}
              {/* Users, Categories & Settings are admin-only — guarded here too so a forced nav can't mount them. */}
              {isAdminRole && activeNav === 'users' ? <UsersAdmin currentUserId={user?.id} /> : null}
              {isAdminRole && activeNav === 'categories' ? <CategoriesAdmin /> : null}
              {isAdminRole && activeNav === 'settings' ? (
                <>
                  <SettingsAdmin />
                  <div style={{ marginTop: 20 }}>
                    <UserProfile key={user?.id} user={user} onProfileUpdated={setUser} />
                  </div>
                </>
              ) : null}
            </div>
          ) : (
            <div>
              {activeNav === 'dashboard' ? <UserApplications applications={applications} loading={appsLoading} /> : null}
              {/* Volunteers get their own task hub as an extra module on top of the user dashboard. */}
              {activeNav === 'mytasks' && isVolunteer ? <VolunteerTasksPanel /> : null}
              {activeNav === 'messages' ? <Messages /> : null}
              {activeNav === 'myadoptions' ? <MyAdoptionsPanel /> : null}
              {activeNav === 'impact' ? <ImpactPanel /> : null}
              {activeNav === 'mydonations' ? <MyDonationsPanel /> : null}
              {activeNav === 'profile' ? <UserProfile key={user?.id} user={user} onProfileUpdated={setUser} /> : null}
              {/* default user sections */}
              {activeNav === 'dashboard' ? <UserProfile key={user?.id} user={user} onProfileUpdated={setUser} /> : null}
            </div>
          )}
        </main>
      </div>
    </div>
  );
}

