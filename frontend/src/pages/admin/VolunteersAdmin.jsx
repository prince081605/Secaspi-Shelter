import { useEffect, useState } from 'react';
import {
  adminListVolunteers,
  adminCreateVolunteer,
  adminUpdateVolunteer,
  adminDeleteVolunteer,
  adminCreateVolunteerTask,
  adminUpdateVolunteerTask,
  adminDeleteVolunteerTask,
} from '../../lib/volunteersApi';
import { adminListUsers } from '../../lib/usersApi';
import StatusBadge from '../../components/StatusBadge';
import ConfirmButton from '../../components/ConfirmButton';

const TASK_STATUSES = ['assigned', 'ongoing', 'completed'];
const NEXT_TASK_STATUS = { assigned: 'ongoing', ongoing: 'completed' };

function AddVolunteerForm({ onCancel, onAdded }) {
  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const [selected, setSelected] = useState(null);
  const [availability, setAvailability] = useState('');
  const [notes, setNotes] = useState('');
  const [state, setState] = useState({ status: 'idle', error: '' });

  const search = async (e) => {
    e.preventDefault();
    setState({ status: 'loading', error: '' });
    try {
      const data = await adminListUsers({ q });
      setResults((data?.data || []).filter((u) => u.role !== 'admin'));
      setState({ status: 'idle', error: '' });
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Search failed.' });
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!selected) return;
    setState({ status: 'loading', error: '' });
    try {
      await adminCreateVolunteer({ user_id: selected.id, availability, performance_notes: notes });
      onAdded();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to add volunteer.' });
    }
  };

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
          <div>Adding <strong>{selected.full_name}</strong> ({selected.email}) as a volunteer.</div>
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

  const advanceTask = async (task) => {
    const next = NEXT_TASK_STATUS[task.status];
    if (!next) return;
    try {
      await adminUpdateVolunteerTask(task.id, { status: next });
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to update task.');
    }
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
                  <td style={{ display: 'flex', gap: 6 }}>
                    {NEXT_TASK_STATUS[t.status] && (
                      <button className="dashBtn dashBtnPrimary" onClick={() => advanceTask(t)}>
                        Mark {NEXT_TASK_STATUS[t.status]}
                      </button>
                    )}
                    <button className="dashBtn dashBtnDanger" aria-label="Delete task" onClick={() => deleteTask(t)}>✕</button>
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

function VolunteerRow({ volunteer, onChanged }) {
  const [expanded, setExpanded] = useState(false);
  const [hours, setHours] = useState(volunteer.hours_rendered);
  const [error, setError] = useState('');

  const saveHours = async () => {
    setError('');
    try {
      await adminUpdateVolunteer(volunteer.id, { hours_rendered: Number(hours) || 0 });
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to save hours.');
    }
  };

  const remove = async () => {
    setError('');
    try {
      await adminDeleteVolunteer(volunteer.id);
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to remove volunteer.');
    }
  };

  return (
    <>
      <tr>
        <td>{volunteer.user?.full_name}<br /><span style={{ fontSize: 12, color: 'var(--muted)' }}>{volunteer.user?.email}</span></td>
        <td>{volunteer.availability || '—'}</td>
        <td style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
          <input className="ui-input" type="number" min="0" style={{ width: 80 }} value={hours} onChange={(e) => setHours(e.target.value)} />
          <button className="dashBtn" onClick={saveHours}>Save</button>
        </td>
        <td>{volunteer.tasks.length}</td>
        <td style={{ display: 'flex', gap: 6 }}>
          <button className="dashBtn" onClick={() => setExpanded((v) => !v)}>{expanded ? 'Hide' : 'Tasks'}</button>
          <ConfirmButton confirmLabel={`Remove ${volunteer.user?.full_name}?`} onConfirm={remove}>Remove</ConfirmButton>
        </td>
      </tr>
      {error && (
        <tr><td colSpan={5}><div className="ui-error">{error}</div></td></tr>
      )}
      {expanded && (
        <tr>
          <td colSpan={5} className="dashExpandPanel">
            {volunteer.performance_notes && <div><strong>Notes:</strong> {volunteer.performance_notes}</div>}
            <TasksPanel volunteer={volunteer} onChanged={onChanged} />
          </td>
        </tr>
      )}
    </>
  );
}

export default function VolunteersAdmin() {
  const [volunteers, setVolunteers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [showAdd, setShowAdd] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListVolunteers()
      .then((data) => {
        if (!mounted) return;
        setVolunteers(data?.data || []);
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load volunteers.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [refreshKey]);

  const refresh = () => {
    setShowAdd(false);
    setRefreshKey((k) => k + 1);
  };

  return (
    <>
      <div className="dashSectionTitle">🤝 Volunteer Management</div>
      {error && <div className="ui-error">{error}</div>}

      <button className="dashBtn dashBtnPrimary" onClick={() => setShowAdd((v) => !v)}>
        {showAdd ? 'Close' : '+ Add volunteer'}
      </button>

      {showAdd && <AddVolunteerForm onCancel={() => setShowAdd(false)} onAdded={refresh} />}

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : volunteers.length === 0 ? (
        <div className="ui-empty">No volunteers yet.</div>
      ) : (
        <div className="dashTableWrap" style={{ marginTop: 10 }}>
          <table className="dashTable">
            <thead>
              <tr>
                <th>Volunteer</th>
                <th>Availability</th>
                <th>Hours rendered</th>
                <th>Tasks</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {volunteers.map((v) => (
                <VolunteerRow key={v.id} volunteer={v} onChanged={refresh} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
