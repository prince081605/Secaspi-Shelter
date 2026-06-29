import { useEffect, useState } from 'react';
import {
  adminListVolunteers,
  adminCreateVolunteer,
  adminUpdateVolunteer,
  adminDeleteVolunteer,
  adminCreateVolunteerTask,
  adminUpdateVolunteerTask,
  adminDeleteVolunteerTask,
  adminListVolunteerApplications,
  adminUpdateVolunteerApplication,
  adminMarkVolunteerApplicationRead,
} from '../../lib/volunteersApi';
import { adminListUsers } from '../../lib/usersApi';
import StatusBadge from '../../components/StatusBadge';
import ConfirmButton from '../../components/ConfirmButton';
import Pagination from '../../components/Pagination';

const NEXT_TASK_STATUS = { assigned: 'ongoing', ongoing: 'completed' };

function AddPersonForm({ type, onCancel, onAdded }) {
  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const [selected, setSelected] = useState(null);
  const [availability, setAvailability] = useState('');
  const [notes, setNotes] = useState('');
  const [state, setState] = useState({ status: 'idle', error: '' });

  const loadUsers = async (query = '') => {
    setState({ status: 'loading', error: '' });
    try {
      const data = await adminListUsers(query ? { q: query } : {});
      // Admins and existing staff/volunteers aren't valid picks here.
      setResults((data?.data || []).filter((u) => u.role !== 'admin' && u.role !== 'volunteer' && u.role !== 'staff'));
      setState({ status: 'idle', error: '' });
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to load users.' });
    }
  };

  useEffect(() => {
    loadUsers();
  }, []);

  const search = (e) => {
    e.preventDefault();
    loadUsers(q);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!selected) return;
    setState({ status: 'loading', error: '' });
    try {
      await adminCreateVolunteer({ user_id: selected.id, type, availability, performance_notes: notes });
      onAdded();
    } catch (err) {
      setState({ status: 'error', error: err?.message || `Failed to add ${type}.` });
    }
  };

  const typeLabel = type === 'staff' ? 'staff member' : 'volunteer';
  const typeVerb = type === 'staff' ? 'as staff' : 'as a volunteer';

  return (
    <div className="dashCard" style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      {!selected ? (
        <>
          <form onSubmit={search} style={{ display: 'flex', gap: 8 }}>
            <input className="ui-input" placeholder="Search user by name/email" aria-label="Search users by name or email" value={q} onChange={(e) => setQ(e.target.value)} />
            <button className="dashBtn" type="submit">Search</button>
            <button className="dashBtn" type="button" onClick={onCancel}>Cancel</button>
          </form>
          {results.length > 0 && (
            <div className="dashTableWrap" style={{ marginTop: 10 }}>
              <table className="dashTable">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th></th></tr></thead>
                <tbody>
                  {results.map((u) => (
                    <tr key={u.id}>
                      <td>{u.full_name}</td>
                      <td>{u.email}</td>
                      <td>{u.role}</td>
                      <td><button className="dashBtn dashBtnPrimary" onClick={() => setSelected(u)}>Select</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      ) : (
        <form onSubmit={handleSubmit}>
          <div>Adding <strong>{selected.full_name}</strong> ({selected.email}) {typeVerb}.</div>
          <div className="ui-field">
            <label className="ui-label">Availability</label>
            <input className="ui-input" placeholder="e.g. weekends" value={availability} onChange={(e) => setAvailability(e.target.value)} />
          </div>
          <div className="ui-field">
            <label className="ui-label">Notes</label>
            <textarea className="ui-input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="ui-btn-primary" type="submit" disabled={state.status === 'loading'}>
              {state.status === 'loading' ? 'Adding…' : 'Confirm add'}
            </button>
            <button className="dashBtn" type="button" onClick={() => setSelected(null)}>Back</button>
          </div>
        </form>
      )}
    </div>
  );
}

function TasksPanel({ volunteer, onChanged }) {
  const [taskName, setTaskName] = useState('');
  const [assignedDate, setAssignedDate] = useState('');
  const [error, setError] = useState('');

  const addTask = async (e) => {
    e.preventDefault();
    setError('');
    try {
      await adminCreateVolunteerTask(volunteer.id, { task_name: taskName, assigned_date: assignedDate || null });
      setTaskName('');
      setAssignedDate('');
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to add task.');
    }
  };

  const setStatus = async (task, status) => {
    setError('');
    try {
      await adminUpdateVolunteerTask(task.id, { status });
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to update task.');
    }
  };

  const advanceTask = (task) => {
    const next = NEXT_TASK_STATUS[task.status];
    if (next) setStatus(task, next);
  };

  const deleteTask = async (task) => {
    try {
      await adminDeleteVolunteerTask(task.id);
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to delete task.');
    }
  };

  return (
    <div style={{ marginTop: 10 }}>
      {error && <div className="ui-error">{error}</div>}
      {volunteer.tasks.length === 0 ? (
        <div className="ui-empty">No tasks assigned yet.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead><tr><th>Task</th><th>Status</th><th>Date</th><th></th></tr></thead>
            <tbody>
              {volunteer.tasks.map((t) => (
                <tr key={t.id}>
                  <td>{t.task_name}</td>
                  <td><StatusBadge status={t.status} /></td>
                  <td>{t.assigned_date || '—'}</td>
                  <td className="dashActionsCell">
                    <span className="dashActionsRow">
                      {t.status === 'requested' && (
                        <button className="dashBtn dashBtnPrimary" onClick={() => setStatus(t, 'assigned')}>
                          Confirm
                        </button>
                      )}
                      {NEXT_TASK_STATUS[t.status] && (
                        <button className="dashBtn dashBtnPrimary" onClick={() => advanceTask(t)}>
                          Mark {NEXT_TASK_STATUS[t.status]}
                        </button>
                      )}
                      <button className="dashBtn dashBtnDanger" aria-label={t.status === 'requested' ? 'Decline task' : 'Delete task'} onClick={() => deleteTask(t)}>✕</button>
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <form onSubmit={addTask} style={{ display: 'flex', gap: 6, marginTop: 8, alignItems: 'flex-end' }}>
        <input className="ui-input" style={{ maxWidth: 200 }} placeholder="Task name *" required value={taskName} onChange={(e) => setTaskName(e.target.value)} />
        <input className="ui-input" style={{ maxWidth: 150 }} type="date" value={assignedDate} onChange={(e) => setAssignedDate(e.target.value)} />
        <button className="dashBtn dashBtnPrimary" type="submit">+ Assign task</button>
      </form>
    </div>
  );
}

function PersonnelRow({ personnel, onChanged }) {
  const [expanded, setExpanded] = useState(false);
  const [hours, setHours] = useState(personnel.hours_rendered);
  const [error, setError] = useState('');

  const saveHours = async () => {
    setError('');
    try {
      await adminUpdateVolunteer(personnel.id, { hours_rendered: Number(hours) || 0 });
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to save hours.');
    }
  };

  const remove = async () => {
    setError('');
    try {
      await adminDeleteVolunteer(personnel.id);
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to remove personnel.');
    }
  };

  return (
    <>
      <tr>
        <td>{personnel.user?.full_name}<br /><span style={{ fontSize: 12, color: 'var(--muted)' }}>{personnel.user?.email}</span></td>
        <td>{personnel.availability || '—'}</td>
        <td className="dashActionsCell">
          <span className="dashActionsRow">
            <input className="ui-input" type="number" min="0" style={{ width: 80 }} value={hours} onChange={(e) => setHours(e.target.value)} />
            <button className="dashBtn" onClick={saveHours}>Save</button>
          </span>
        </td>
        <td>{personnel.tasks.length}</td>
        <td className="dashActionsCell">
          <span className="dashActionsRow">
            <button className="dashBtn" onClick={() => setExpanded((v) => !v)}>{expanded ? 'Hide' : 'Tasks'}</button>
            <ConfirmButton confirmLabel={`Remove ${personnel.user?.full_name}?`} onConfirm={remove}>Remove</ConfirmButton>
          </span>
        </td>
      </tr>
      {error && (
        <tr><td colSpan={5}><div className="ui-error">{error}</div></td></tr>
      )}
      {expanded && (
        <tr>
          <td colSpan={5} className="dashExpandPanel">
            {personnel.performance_notes && <div><strong>Notes:</strong> {personnel.performance_notes}</div>}
            <TasksPanel volunteer={personnel} onChanged={onChanged} />
          </td>
        </tr>
      )}
    </>
  );
}

function RequestRow({ application, onChanged }) {
  const [expanded, setExpanded] = useState(false);
  const [notes, setNotes] = useState(application.admin_notes || '');
  const [error, setError] = useState('');
  const isUnread = !application.read_at;

  const decide = async (status) => {
    setError('');
    try {
      await adminUpdateVolunteerApplication(application.id, { status, admin_notes: notes || null });
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to update application.');
    }
  };

  // Opening Details clears the unread highlight, independent of any approve/reject action.
  const toggleDetails = async () => {
    const next = !expanded;
    setExpanded(next);
    if (next && isUnread) {
      try {
        await adminMarkVolunteerApplicationRead(application.id);
        onChanged();
      } catch {
        // non-critical: the highlight just won't clear until the next interaction
      }
    }
  };

  return (
    <>
      <tr className={isUnread ? 'dashRowUnread' : ''}>
        <td>{application.applicant?.full_name || '—'}<br /><span style={{ fontSize: 12, color: 'var(--muted)' }}>{application.applicant?.email}</span></td>
        <td>{application.availability || '—'}</td>
        <td><StatusBadge status={application.status} /></td>
        <td>{(application.created_at || '').slice(0, 10)}</td>
        <td className="dashActionsCell">
          <span className="dashActionsRow">
            {application.status === 'pending' && (
              <>
                <button className="dashBtn dashBtnPrimary" onClick={() => decide('approved')}>Approve</button>
                <button className="dashBtn dashBtnDanger" onClick={() => decide('rejected')}>Reject</button>
              </>
            )}
            <button className="dashBtn" onClick={toggleDetails}>{expanded ? 'Hide' : 'Details'}</button>
          </span>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={5} className="dashExpandPanel">
            {error && <div className="ui-error">{error}</div>}
            <dl className="dashInfoList">
              <div><dt>Phone</dt><dd>{application.applicant?.phone || '—'}</dd></div>
              <div className="dashInfoFull"><dt>Experience</dt><dd>{application.experience || '—'}</dd></div>
              <div className="dashInfoFull"><dt>Why volunteer?</dt><dd>{application.reason || '—'}</dd></div>
            </dl>
            <div className="ui-field" style={{ marginTop: 8 }}>
              <label className="ui-label">Admin notes (shared with the applicant on decision)</label>
              <textarea className="ui-input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
            </div>
          </td>
        </tr>
      )}
    </>
  );
}

function VolunteerRequests() {
  const [applications, setApplications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [status, setStatusFilter] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  // Changing the status filter starts a fresh result set, so jump back to page 1.
  const changeStatus = (value) => {
    setPage(1);
    setStatusFilter(value);
  };

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListVolunteerApplications({ status, page })
      .then((data) => {
        if (!mounted) return;
        setApplications(data?.data || []);
        setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load volunteer requests.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [status, refreshKey, page]);

  // Keep the current page on refresh (approve/reject); only the status filter resets it.
  const refresh = () => setRefreshKey((k) => k + 1);

  return (
    <>
      {error && <div className="ui-error">{error}</div>}
      <div className="dashFilterBar">
        <select className="ui-input" style={{ maxWidth: 180 }} aria-label="Filter volunteer requests by status" value={status} onChange={(e) => changeStatus(e.target.value)}>
          <option value="">All statuses</option>
          {['pending', 'approved', 'rejected'].map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : applications.length === 0 ? (
        <div className="ui-empty">No volunteer requests match this filter.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>Applicant</th>
                <th>Availability</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {applications.map((a) => (
                <RequestRow key={a.id} application={a} onChanged={refresh} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && applications.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}

function PersonnelRoster({ type, onChanged }) {
  const [personnel, setPersonnel] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [showAdd, setShowAdd] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListVolunteers({ type, page })
      .then((data) => {
        if (!mounted) return;
        setPersonnel(data?.data || []);
        setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || `Failed to load ${type}.`);
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [type, refreshKey, page]);

  // Keep the current page on refresh so an open Tasks panel isn't collapsed.
  const refresh = () => {
    setShowAdd(false);
    setRefreshKey((k) => k + 1);
  };

  const typeLabel = type === 'staff' ? 'staff' : 'volunteers';
  const addLabel = type === 'staff' ? '+ Add staff' : '+ Add volunteer';

  return (
    <>
      {error && <div className="ui-error">{error}</div>}

      <button className="dashBtn dashBtnPrimary" onClick={() => setShowAdd((v) => !v)}>
        {showAdd ? 'Close' : addLabel}
      </button>

      {showAdd && <AddPersonForm type={type} onCancel={() => setShowAdd(false)} onAdded={refresh} />}

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : personnel.length === 0 ? (
        <div className="ui-empty">No {typeLabel} yet.</div>
      ) : (
        <div className="dashTableWrap" style={{ marginTop: 10 }}>
          <table className="dashTable">
            <thead>
              <tr>
                <th>{type === 'staff' ? 'Staff Member' : 'Volunteer'}</th>
                <th>Availability</th>
                <th>Hours rendered</th>
                <th>Tasks</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {personnel.map((p) => (
                <PersonnelRow key={p.id} personnel={p} onChanged={refresh} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && personnel.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}

export default function VolunteersAdmin() {
  const [mode, setMode] = useState('volunteers');
  const [subMode, setSubMode] = useState('roster');

  return (
    <>
      <h2 className="dashSectionTitle">👥 Personnel Management</h2>

      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        <button
          className={mode === 'volunteers' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
          onClick={() => setMode('volunteers')}
        >
          🤝 Volunteers
        </button>
        <button
          className={mode === 'staff' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
          onClick={() => setMode('staff')}
        >
          👔 Staff
        </button>
      </div>

      {mode === 'volunteers' ? (
        <>
          <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
            <button
              className={subMode === 'roster' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
              onClick={() => setSubMode('roster')}
            >
              📋 Volunteers
            </button>
            <button
              className={subMode === 'requests' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
              onClick={() => setSubMode('requests')}
            >
              📩 Requests
            </button>
          </div>

          {subMode === 'requests' ? (
            <VolunteerRequests />
          ) : (
            <PersonnelRoster type="volunteer" />
          )}
        </>
      ) : (
        <PersonnelRoster type="staff" />
      )}
    </>
  );
}
