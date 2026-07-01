import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import './Dashboard.css';
import { auth } from '../../lib/auth';
import { listMyAdoptionApplications, listMyFosterApplications, browseAnimals } from '../../lib/animalsApi';
import { updateProfile, changePassword } from '../../lib/profileApi';
import { getPublicSettings } from '../../lib/settingsApi';
import {
  Clock, Heart, User, Pencil, Lock, ClipboardList, Dog, Trophy, LayoutDashboard,
  ArrowLeft, Menu, X, LogOut, MessageSquare, PawPrint, Bell, Inbox, HeartHandshake,
  Siren, Calendar, Wrench, HandCoins, BarChart3, Users, UsersRound, Brain, Settings,
  ChevronRight,
} from 'lucide-react';
import AnimalsAdmin from '../admin/AnimalsAdmin';
import AdoptionRequestsAdmin from '../admin/AdoptionRequestsAdmin';
import RescueReportsAdmin from '../admin/RescueReportsAdmin';
import DonationsAdmin from '../admin/DonationsAdmin';
import UsersAdmin from '../admin/UsersAdmin';
import { adminGetOverview, adminGetPendingCounts } from '../../lib/dashboardApi';
import { getMyVolunteer, requestVolunteerTask } from '../../lib/volunteersApi';
import VolunteersAdmin from '../admin/VolunteersAdmin';
import ReportsAdmin from '../admin/ReportsAdmin';
import AnalyticsAdmin from '../admin/AnalyticsAdmin';
import Messages from '../Messages';
import ImpactPanel from './ImpactPanel';
import FaqTrainingAdmin from '../admin/FaqTrainingAdmin';
import SettingsAdmin from '../admin/SettingsAdmin';
import VisitationsAdmin from '../admin/VisitationsAdmin';
import RemindersAdmin from '../admin/RemindersAdmin';
import StatusBadge from '../../components/StatusBadge';
import NotificationBell from '../../components/NotificationBell';

const fallbackRole = 'user';

// Which collapsible sidebar category each admin nav item belongs to. Used to
// auto-expand the category that contains the active item.
const ITEM_CATEGORY = {
  animals: 'cat_animals', reminders: 'cat_animals',
  requests: 'cat_requests', rescues: 'cat_requests', visitations: 'cat_requests', messages: 'cat_requests',
  donations: 'cat_ops', reports: 'cat_ops', users: 'cat_ops', settings: 'cat_ops', volunteers: 'cat_ops', faqs: 'cat_ops',
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
  requests: 'staff', rescues: 'staff', visitations: 'staff', messages: 'staff',
  donations: 'staff', reports: 'staff', volunteers: 'staff',
  users: 'admin', settings: 'admin', faqs: 'admin',
};


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
      <h2 className="dashSectionTitle"><Clock size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Recent activity</h2>
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
      <h2 className="dashSectionTitle"><Heart size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />My Applications</h2>
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

// Volunteer's own task hub: their assigned tasks + a request-a-task form. Reuses the
// same endpoints as the public VolunteerApply page (GET /volunteer/me, POST /volunteer/tasks).
function VolunteerTasksPanel() {
  const [loading, setLoading] = useState(true);
  const [volunteer, setVolunteer] = useState(null);
  const [taskName, setTaskName] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const load = () => {
    setLoading(true);
    getMyVolunteer()
      .then((v) => setVolunteer(v?.volunteer || null))
      .catch(() => setVolunteer(null))
      .finally(() => setLoading(false));
  };
  useEffect(() => { load(); }, []);

  const handleRequestTask = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');
    try {
      await requestVolunteerTask({ task_name: taskName });
      setTaskName('');
      load();
    } catch (err) {
      setError(err?.message || 'Failed to request task.');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <div className="ui-empty">Loading…</div>;
  if (!volunteer) {
    return (
      <>
        <h2 className="dashSectionTitle"><ClipboardList size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />My Tasks</h2>
        <div className="ui-empty">Your volunteer profile isn't set up yet. Please contact the shelter team.</div>
      </>
    );
  }

  return (
    <>
      <h2 className="dashSectionTitle"><ClipboardList size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />My Tasks</h2>
      {error && <div className="ui-error">{error}</div>}
      <form onSubmit={handleRequestTask} style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 16 }}>
        <div className="ui-field" style={{ flex: 1, marginBottom: 0 }}>
          <label className="ui-label ui-label-required">Task you'd like to do</label>
          <input
            className="ui-input"
            value={taskName}
            onChange={(e) => setTaskName(e.target.value)}
            placeholder="e.g. Walk the dogs on Saturday"
            required
          />
        </div>
        <button className="ui-btn-primary" type="submit" disabled={submitting || !taskName.trim()}>
          {submitting ? 'Sending…' : 'Request task'}
        </button>
      </form>
      {(!volunteer.tasks || volunteer.tasks.length === 0) ? (
        <div className="ui-empty">No tasks yet. Request one above to get started!</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead><tr><th>Task</th><th>Status</th><th>When</th></tr></thead>
            <tbody>
              {volunteer.tasks.map((t) => (
                <tr key={t.id}>
                  <td>{t.task_name}</td>
                  <td><StatusBadge status={t.status} /></td>
                  <td>{t.status === 'requested' ? 'Awaiting confirmation' : (t.assigned_date || '—')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

// Read-only animal roster for volunteers — uses the public browse endpoint (no admin
// fields, no write controls), so it works without any staff-level permission.
function ReadOnlyAnimals() {
  const [animals, setAnimals] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let mounted = true;
    browseAnimals({ per_page: 50 })
      .then((data) => { if (mounted) setAnimals(Array.isArray(data?.data) ? data.data : []); })
      .catch((err) => { if (mounted) setError(err?.message || 'Failed to load animals.'); })
      .finally(() => { if (mounted) setLoading(false); });
    return () => { mounted = false; };
  }, []);

  return (
    <>
      <h2 className="dashSectionTitle"><Dog size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Animals</h2>
      {error && <div className="ui-error">{error}</div>}
      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : animals.length === 0 ? (
        <div className="ui-empty">No animals to show.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead><tr><th>Name</th><th>Species</th><th>Breed</th><th>Status</th></tr></thead>
            <tbody>
              {animals.map((a) => (
                <tr key={a.id}>
                  <td>
                    <div className="dashFlexRow">
                      {a.photo ? (
                        <img
                          src={a.photo.startsWith('http') ? a.photo : `${import.meta.env.VITE_API_BASE_URL}/storage/${a.photo}`}
                          alt={a.name || 'animal'}
                          className="dashThumbSm"
                        />
                      ) : null}
                      {a.name || 'Unnamed'}
                    </div>
                  </td>
                  <td>{a.species || '—'}</td>
                  <td>{a.breed || '—'}</td>
                  <td><StatusBadge status={a.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
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
  const [pendingRescueCount, setPendingRescueCount] = useState(0);
  const [pendingAdoptionCount, setPendingAdoptionCount] = useState(0);
  const [pendingFosterCount, setPendingFosterCount] = useState(0);
  const [pendingDonationCount, setPendingDonationCount] = useState(0);
  const [pendingVisitationCount, setPendingVisitationCount] = useState(0);
  const [overdueReminderCount, setOverdueReminderCount] = useState(0);
  const [pendingVolunteerCount, setPendingVolunteerCount] = useState(0);
  // Whether the public AI assistant is switched on. When off, the assistant widget renders nothing
  // (see AiAssistant.jsx), so the "AI Training" (FAQ KB) nav item + panel are hidden. Default true
  // so a transient settings-fetch failure doesn't wrongly hide it from an admin.
  const [aiEnabled, setAiEnabled] = useState(true);

  // keep empty when data isn't available
  const isAdminRole = role === 'admin';
  const isStaffPlus = atLeast(role, 'staff'); // staff + admin: operational dashboard
  const isVolunteer = role === 'volunteer';   // focused tasks + read-only animals view

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
        // Land each role on its primary dashboard: staff/admin → operations, volunteer →
        // their tasks, everyone else → the user dashboard.
        setTab(atLeast(r, 'staff') ? 'admin' : r === 'volunteer' ? 'volunteer' : 'user');
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

  // Read whether the AI assistant is on (mirrors AiAssistant.jsx) to gate the "AI Training" nav.
  useEffect(() => {
    let mounted = true;
    getPublicSettings()
      .then((s) => { if (mounted) setAiEnabled(String(s?.ai_assistant_enabled) === '1'); })
      .catch(() => { /* leave default (visible) on a transient failure */ });
    return () => { mounted = false; };
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
    return [
      { key: 'admin', label: isAdminRole ? 'Admin Dashboard' : 'Staff Dashboard', show: isStaffPlus },
      { key: 'volunteer', label: 'Volunteer Dashboard', show: isVolunteer },
      { key: 'user', label: 'User Dashboard', show: true },
    ].filter((t) => t.show);
  }, [isAdminRole, isStaffPlus, isVolunteer]);

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
        { key: 'rescues', label: 'Rescue Reports', icon: Siren, badge: pendingRescueCount },
        { key: 'visitations', label: 'Visit Requests', icon: Calendar, badge: pendingVisitationCount },
        { key: 'messages', label: 'Messages', icon: MessageSquare },
      ],
    },
    {
      key: 'cat_ops', label: 'Operations', icon: Wrench,
      items: [
        { key: 'donations', label: 'Donations', icon: HandCoins, badge: pendingDonationCount },
        { key: 'reports', label: 'Reports', icon: BarChart3 },
        { key: 'users', label: 'Users', icon: Users },
        { key: 'volunteers', label: 'Personnel', icon: UsersRound, badge: pendingVolunteerCount },
        { key: 'faqs', label: 'AI Training', icon: Brain },
        { key: 'settings', label: 'Settings', icon: Settings },
      ],
    },
  ];

  // Hide nav items above the current role (e.g. staff never see Users/Settings), then
  // drop any category left empty. Admin sees everything. "AI Training" (faqs) is also hidden
  // whenever the AI assistant is switched off — its FAQ KB has no user-facing surface then.
  const visibleNavCategories = navCategories
    .map((cat) => ({
      ...cat,
      items: cat.items.filter((it) =>
        atLeast(role, ITEM_MIN_ROLE[it.key] || 'admin') && !(it.key === 'faqs' && !aiEnabled)),
    }))
    .filter((cat) => cat.items.length > 0);

  const [activeNav, setActiveNav] = useState('dashboard');
  // If the AI assistant is switched off while the admin is sitting on "AI Training", bounce them
  // back to the overview so they aren't left on a now-hidden, blank panel.
  useEffect(() => {
    if (!aiEnabled && activeNav === 'faqs') setActiveNav('dashboard');
  }, [aiEnabled, activeNav]);
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

            {activeTab !== 'volunteer' && (
              <button
                className={'dashNavBtn ' + (activeNav === 'dashboard' ? 'dashNavBtnActive' : '')}
                onClick={() => setActiveNav('dashboard')}
              >
                <LayoutDashboard size={16} style={{ verticalAlign: '-3px' }} /> Dashboard
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

            {activeTab === 'volunteer' && (
              <>
                <button
                  className={'dashNavBtn ' + (activeNav === 'myanimals' ? '' : 'dashNavBtnActive')}
                  onClick={() => setActiveNav('mytasks')}
                >
                  <ClipboardList size={16} style={{ verticalAlign: '-3px' }} /> My Tasks
                </button>
                <button
                  className={'dashNavBtn ' + (activeNav === 'myanimals' ? 'dashNavBtnActive' : '')}
                  onClick={() => setActiveNav('myanimals')}
                >
                  <Dog size={16} style={{ verticalAlign: '-3px' }} /> Animals
                </button>
              </>
            )}

            {activeTab === 'user' && (
              <>
                <button
                  className={'dashNavBtn ' + (activeNav === 'impact' ? 'dashNavBtnActive' : '')}
                  onClick={() => setActiveNav('impact')}
                >
                  <Trophy size={16} style={{ verticalAlign: '-3px' }} /> My Impact
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
                  : activeTab === 'volunteer'
                    ? 'View your tasks and the animals in our care.'
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

          {activeTab !== 'volunteer' && (
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
              {activeNav === 'animals' ? <AnimalsAdmin /> : null}
              {activeNav === 'requests' ? <AdoptionRequestsAdmin onUnreadChanged={fetchPendingCounts} /> : null}
              {activeNav === 'rescues' ? <RescueReportsAdmin onUnreadChanged={fetchPendingCounts} /> : null}
              {activeNav === 'visitations' ? <VisitationsAdmin /> : null}
              {activeNav === 'messages' ? <Messages staff /> : null}
              {activeNav === 'reminders' ? <RemindersAdmin onChanged={fetchPendingCounts} /> : null}
              {activeNav === 'donations' ? <DonationsAdmin isAdmin={isAdminRole} /> : null}
              {activeNav === 'volunteers' ? <VolunteersAdmin /> : null}
              {activeNav === 'reports' ? <ReportsAdmin isAdmin={isAdminRole} /> : null}
              {/* Users & Settings are admin-only — guarded here too so a forced nav can't mount them. */}
              {isAdminRole && aiEnabled && activeNav === 'faqs' ? <FaqTrainingAdmin /> : null}
              {isAdminRole && activeNav === 'users' ? <UsersAdmin currentUserId={user?.id} /> : null}
              {isAdminRole && activeNav === 'settings' ? (
                <>
                  <SettingsAdmin onSaved={(f) => setAiEnabled(String(f.ai_assistant_enabled) === '1')} />
                  <div style={{ marginTop: 20 }}>
                    <UserProfile key={user?.id} user={user} onProfileUpdated={setUser} />
                  </div>
                </>
              ) : null}
            </div>
          ) : activeTab === 'volunteer' ? (
            <div>
              {activeNav === 'myanimals' ? <ReadOnlyAnimals /> : <VolunteerTasksPanel />}
            </div>
          ) : (
            <div>
              {activeNav === 'dashboard' ? <UserApplications applications={applications} loading={appsLoading} /> : null}
              {activeNav === 'messages' ? <Messages /> : null}
              {activeNav === 'impact' ? <ImpactPanel /> : null}
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

