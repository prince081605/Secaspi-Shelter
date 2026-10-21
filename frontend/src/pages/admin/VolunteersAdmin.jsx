import { useEffect, useState } from 'react';
import ActionMenu from '../../components/ActionMenu';
import { Users, Handshake, Briefcase, ClipboardList, Inbox, Clock, UsersRound } from 'lucide-react';
import AttendanceAdmin from './AttendanceAdmin';
import TeamsAdmin from './TeamsAdmin';
import {
  adminListVolunteers,
  adminCreateVolunteer,
  adminSearchPersonnelCandidates,
  adminUpdateVolunteer,
  adminDeleteVolunteer,
  adminCreateVolunteerTask,
  adminUpdateVolunteerTask,
  adminDeleteVolunteerTask,
  adminListVolunteerApplications,
  adminUpdateVolunteerApplication,
  adminMarkVolunteerApplicationRead,
} from '../../lib/volunteersApi';
import StatusBadge from '../../components/StatusBadge';
import useConfirm from '../../lib/useConfirm';
import Pagination from '../../components/Pagination';
import DashCard from '../../components/DashCard';
import useIsMobile from '../../lib/useIsMobile';
import { optionLabel, useCategories } from '../../lib/categoriesApi';
import { roleLabel } from '../../lib/permissions';

// 'submitted' is where a task lands once the volunteer sends proof they finished it, so the
// step after it is signing that proof off. 'ongoing' still leads straight to completed — not
// every task produces a photo worth waiting for.
const NEXT_TASK_STATUS = { assigned: 'ongoing', ongoing: 'completed', submitted: 'completed' };

// Completing a task is a Desk Admin's verification of the work (the backend enforces this too):
// staff do tasks themselves, so they move tasks along but never complete them.
function nextStatusFor(task, canSupervise) {
  const next = NEXT_TASK_STATUS[task.status];
  return next === 'completed' && !canSupervise ? null : next;
}

function nextStatusLabel(task, next) {
  if (next !== 'completed') return `Mark ${next}`;
  return task.status === 'submitted' ? 'Verify & complete' : 'Mark completed';
}

function fileSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

// What the volunteer sent as evidence the task is done. Small enough to sit in a table cell,
// clickable through to the full photo — a thumbnail is for spotting that proof exists, not for
// judging it.
function TaskProof({ task }) {
  if (!task.proof_url) {
    return <span className="ui-muted" style={{ fontSize: 12 }}>—</span>;
  }

  return (
    <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start' }}>
      <a href={fileSrc(task.proof_url)} target="_blank" rel="noreferrer" title="Open the full-size photo">
        <img
          src={fileSrc(task.proof_url)}
          alt={`Proof of completion for ${task.task_name}`}
          style={{ width: 56, height: 56, objectFit: 'cover', borderRadius: 6, border: '1px solid var(--line)', display: 'block' }}
        />
      </a>
      <span style={{ fontSize: 12, color: 'var(--ink-soft)', maxWidth: 200, display: 'grid', gap: 2 }}>
        {task.proof_note && <span>{task.proof_note}</span>}
        {task.proof_submitted_at && (
          <span className="ui-muted">Sent {new Date(task.proof_submitted_at).toLocaleString()}</span>
        )}
        {task.status === 'completed' && task.verified_by && (
          <span style={{ color: '#1b7a3d', fontWeight: 600 }}>Verified by {task.verified_by}</span>
        )}
      </span>
    </div>
  );
}

function AddPersonForm({ type, onCancel, onAdded }) {
  const confirm = useConfirm();
  const isMobile = useIsMobile();
  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const [selected, setSelected] = useState(null);
  const [availability, setAvailability] = useState('');
  const [notes, setNotes] = useState('');
  const [state, setState] = useState({ status: 'idle', error: '' });

  const loadUsers = async (query = '') => {
    setState({ status: 'loading', error: '' });
    try {
      // Only plain user accounts come back: not personnel already, and not an admin type.
      const data = await adminSearchPersonnelCandidates(query);
      setResults(data?.users || []);
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
    const label = type === 'staff' ? 'staff member' : 'volunteer';
    const ok = await confirm({
      title: `Add ${selected.full_name} as ${type === 'staff' ? 'staff' : 'a volunteer'}?`,
      message: `Their account is promoted to the ${label} role and they gain the matching dashboard access.`,
      confirmLabel: `Add ${label}`,
      summary: [
        { label: 'Name', value: selected.full_name },
        { label: 'Email', value: selected.email },
        { label: 'Availability', value: availability },
      ],
    });
    if (!ok) return;
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
          <form onSubmit={search} style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <input className="ui-input" placeholder="Search user by name/email" aria-label="Search users by name or email" value={q} onChange={(e) => setQ(e.target.value)} />
            <button className="dashBtn" type="submit">Search</button>
            <button className="dashBtn" type="button" onClick={onCancel}>Cancel</button>
          </form>
          {results.length > 0 && (
            isMobile ? (
              <div className="dashCardList" style={{ marginTop: 10 }}>
                {results.map((u) => (
                  <DashCard
                    key={u.id}
                    title={u.full_name}
                    subtitle={u.email}
                    fields={[{ label: 'Role', value: roleLabel(u.role) }]}
                    actions={<button className="dashBtn dashBtnPrimary" onClick={() => setSelected(u)}>Select</button>}
                  />
                ))}
              </div>
            ) : (
              <div className="dashTableWrap" style={{ marginTop: 10 }}>
                <table className="dashTable">
                  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th></th></tr></thead>
                  <tbody>
                    {results.map((u) => (
                      <tr key={u.id}>
                        <td>{u.full_name}</td>
                        <td>{u.email}</td>
                        <td>{roleLabel(u.role)}</td>
                        <td><button className="dashBtn dashBtnPrimary" onClick={() => setSelected(u)}>Select</button></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )
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
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
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

function TasksPanel({ volunteer, onChanged, canSupervise }) {
  const confirm = useConfirm();
  const isMobile = useIsMobile();
  const [taskName, setTaskName] = useState('');
  const [assignedDate, setAssignedDate] = useState('');
  const [error, setError] = useState('');

  const addTask = async (e) => {
    e.preventDefault();
    const ok = await confirm({
      title: `Assign this task to ${volunteer.user?.full_name || 'this volunteer'}?`,
      message: 'It appears on their volunteer dashboard as assigned work.',
      confirmLabel: 'Assign task',
      summary: [
        { label: 'Task', value: taskName },
        { label: 'Date', value: assignedDate },
      ],
    });
    if (!ok) return;
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
    // Completing a task the volunteer has sent proof for is a sign-off on that proof, not a
    // status flip — say so, and point at the photo they are signing off.
    const signingOff = task.status === 'submitted' && status === 'completed';
    const sendingBack = task.status === 'submitted' && status === 'ongoing';
    const ok = await confirm({
      title: signingOff
        ? 'Verify this task as completed?'
        : sendingBack ? 'Send this task back?' : `Mark this task ${status}?`,
      message: signingOff
        ? 'You are accepting the proof that was sent. They are notified that the task is verified complete.'
        : sendingBack
          ? 'The proof is not accepted. The task goes back to ongoing so they can send a better photo.'
          : 'They see the new task status on their dashboard.',
      confirmLabel: signingOff ? 'Verify & complete' : sendingBack ? 'Send back' : `Mark ${status}`,
      tone: sendingBack ? 'danger' : undefined,
      summary: [
        { label: 'Task', value: task.task_name },
        { label: volunteer.type === 'staff' ? 'Staff member' : 'Volunteer', value: volunteer.user?.full_name },
        { label: 'From', value: task.status },
        { label: 'To', value: status },
        { label: 'Note', value: signingOff ? task.proof_note : '' },
      ],
    });
    if (!ok) return;
    setError('');
    try {
      await adminUpdateVolunteerTask(task.id, { status });
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to update task.');
    }
  };

  const advanceTask = (task) => {
    const next = nextStatusFor(task, canSupervise);
    if (next) setStatus(task, next);
  };

  // The buttons a task row offers, shared by the table and the mobile cards.
  const taskActions = (t) => {
    const next = nextStatusFor(t, canSupervise);
    return (
      <>
        {!canSupervise && t.status === 'submitted' && (
          <span className="ui-muted" style={{ fontSize: 12 }}>Awaiting Desk Admin verification</span>
        )}
        <ActionMenu
          ariaLabel={`Actions for the task ${t.task_name}`}
          items={[
            t.status === 'requested' && { label: 'Confirm', tone: 'primary', onClick: () => setStatus(t, 'assigned') },
            next && { label: nextStatusLabel(t, next), tone: 'primary', onClick: () => advanceTask(t) },
            canSupervise && t.status === 'submitted' && { label: 'Send back', onClick: () => setStatus(t, 'ongoing') },
            { divider: true },
            { label: t.status === 'requested' ? 'Decline task' : 'Delete task', tone: 'danger', onClick: () => deleteTask(t) },
          ]}
        />
      </>
    );
  };

  const deleteTask = async (task) => {
    const ok = await confirm({
      title: 'Delete this task?',
      message: 'It is removed from the volunteer’s task list and cannot be recovered.',
      confirmLabel: 'Delete task',
      tone: 'danger',
      summary: [{ label: 'Task', value: task.task_name }],
    });
    if (!ok) return;
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
      ) : isMobile ? (
        <div className="dashCardList">
          {volunteer.tasks.map((t) => (
            <DashCard
              key={t.id}
              title={t.task_name}
              fields={[
                { label: 'Status', value: <StatusBadge status={t.status} /> },
                { label: 'Date', value: t.assigned_date || '—' },
                t.proof_url && { label: 'Proof', value: <TaskProof task={t} /> },
              ]}
              actions={taskActions(t)}
            />
          ))}
        </div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead><tr><th>Task</th><th>Status</th><th>Date</th><th>Proof</th><th></th></tr></thead>
            <tbody>
              {volunteer.tasks.map((t) => (
                <tr key={t.id}>
                  <td>{t.task_name}</td>
                  <td><StatusBadge status={t.status} /></td>
                  <td>{t.assigned_date || '—'}</td>
                  <td><TaskProof task={t} /></td>
                  <td className="dashActionsCell">
                    <span className="dashActionsRow">{taskActions(t)}</span>
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

// A person's categories as small chips (the managed 'volunteer_category' list).
function CategoryChips({ values }) {
  const lists = useCategories();
  if (!values?.length) return <span className="ui-muted">—</span>;
  return (
    <span className="volCatChips">
      {values.map((v) => <span key={v} className="badge">{optionLabel(lists.volunteer_category, v)}</span>)}
    </span>
  );
}

// What this person helps with. Hidden categories they already have stay on the list for them.
function CategoryEditor({ personnel, onSaved }) {
  const lists = useCategories();
  const [selected, setSelected] = useState(personnel.categories || []);
  const [state, setState] = useState({ status: 'idle', error: '' });
  const shown = (lists.volunteer_category || []).filter((o) => !o.hidden || selected.includes(o.value));
  const dirty = [...selected].sort().join() !== [...(personnel.categories || [])].sort().join();

  const toggle = (value) => setSelected((s) => (s.includes(value) ? s.filter((v) => v !== value) : [...s, value]));

  const save = async () => {
    setState({ status: 'loading', error: '' });
    try {
      await adminUpdateVolunteer(personnel.id, { categories: selected });
      setState({ status: 'idle', error: '' });
      onSaved();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to save categories.' });
    }
  };

  return (
    <fieldset className="volCatEdit">
      <legend className="ui-label">Categories</legend>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      {shown.length === 0 ? (
        <div className="ui-muted">No categories yet. Add them in Settings → System data.</div>
      ) : (
        <div className="volCatGrid">
          {shown.map((o) => (
            <label key={o.value} className={selected.includes(o.value) ? 'volCatPick volCatPickOn' : 'volCatPick'}>
              <input type="checkbox" checked={selected.includes(o.value)} onChange={() => toggle(o.value)} />
              {o.label}{o.hidden ? ' (hidden)' : ''}
            </label>
          ))}
        </div>
      )}
      <button className="dashBtn dashBtnPrimary" type="button" onClick={save} disabled={!dirty || state.status === 'loading'} style={{ marginTop: 8 }}>
        {state.status === 'loading' ? 'Saving…' : 'Save categories'}
      </button>
    </fieldset>
  );
}

const CATEGORY_STYLES = `
  .volCatChips { display: inline-flex; flex-wrap: wrap; gap: 4px; }
  .volCatChips .badge { font-size: 11px; padding: 3px 8px; }
  .volCatEdit { border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; margin: 0 0 12px; min-width: 0; }
  .volCatGrid { display: flex; flex-wrap: wrap; gap: 6px; }
  .volCatPick { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid var(--line); border-radius: 999px; cursor: pointer; font-size: 0.88rem; min-height: 36px; }
  .volCatPick input { accent-color: var(--brand); margin: 0; }
  .volCatPickOn { border-color: var(--brand); background: var(--brand-soft); color: var(--brand); }
  .volCatPick:focus-within { outline: 3px solid var(--brand); outline-offset: 2px; }
`;

function PersonnelRow({ personnel, onChanged, canSupervise }) {
  const confirm = useConfirm();
  const isMobile = useIsMobile();
  const [expanded, setExpanded] = useState(false);
  const [hours, setHours] = useState(personnel.hours_rendered);
  const [error, setError] = useState('');

  const saveHours = async () => {
    const ok = await confirm({
      title: 'Save hours rendered?',
      message: 'This is their recorded service total, which their certificate is issued from. Attendance hours are counted in it; the difference is kept as a manual adjustment.',
      confirmLabel: 'Save hours',
      summary: [
        { label: 'Person', value: personnel.user?.full_name },
        { label: 'From', value: String(personnel.hours_rendered ?? 0) },
        { label: 'To', value: String(Number(hours) || 0) },
      ],
    });
    if (!ok) return;
    setError('');
    try {
      await adminUpdateVolunteer(personnel.id, { hours_rendered: Number(hours) || 0 });
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to save hours.');
    }
  };

  const remove = async () => {
    const ok = await confirm({
      title: `Remove ${personnel.user?.full_name}?`,
      message: 'They lose their volunteer access and their assigned tasks go with them. The user account itself stays.',
      confirmLabel: 'Remove',
      tone: 'danger',
      summary: [
        { label: 'Person', value: personnel.user?.full_name },
        { label: 'Hours on record', value: String(personnel.hours_rendered ?? 0) },
      ],
    });
    if (!ok) return;
    setError('');
    try {
      await adminDeleteVolunteer(personnel.id);
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to remove personnel.');
    }
  };

  // Hours come from attendance; only a Desk Admin can override the total (the backend enforces it).
  const hoursControl = canSupervise ? (
    <span className="dashActionsRow">
      <input className="ui-input" type="number" min="0" step="0.25" style={{ width: 90 }} aria-label={`Hours rendered by ${personnel.user?.full_name || 'this person'}`} value={hours} onChange={(e) => setHours(e.target.value)} />
      <button className="dashBtn" onClick={saveHours}>Save</button>
    </span>
  ) : (
    <span>{Number(personnel.hours_rendered || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}</span>
  );
  const actions = (
    <ActionMenu
      ariaLabel={`Actions for ${personnel.user?.full_name || 'this person'}`}
      items={[
        { label: expanded ? 'Hide tasks & categories' : 'Tasks & categories', onClick: () => setExpanded((v) => !v) },
        { divider: true },
        { label: 'Remove from Personnel', tone: 'danger', onClick: remove },
      ]}
    />
  );
  const panel = (
    <>
      {personnel.performance_notes && <div><strong>Notes:</strong> {personnel.performance_notes}</div>}
      <CategoryEditor key={(personnel.categories || []).join()} personnel={personnel} onSaved={onChanged} />
      <TasksPanel volunteer={personnel} onChanged={onChanged} canSupervise={canSupervise} />
    </>
  );

  if (isMobile) {
    return (
      <>
        <DashCard
          title={personnel.user?.full_name}
          subtitle={personnel.user?.email}
          fields={[
            { label: 'Availability', value: personnel.availability || '—' },
            { label: 'Categories', value: <CategoryChips values={personnel.categories} /> },
            { label: 'Hours rendered', value: hoursControl },
            { label: 'Tasks', value: personnel.tasks.length },
          ]}
          actions={actions}
        />
        {error && <div className="ui-error" style={{ marginTop: 8 }}>{error}</div>}
        {expanded && <div className="dashCardExpand">{panel}</div>}
      </>
    );
  }

  return (
    <>
      <tr>
        <td>{personnel.user?.full_name}<br /><span style={{ fontSize: 12, color: 'var(--muted)' }}>{personnel.user?.email}</span></td>
        <td>{personnel.availability || '—'}</td>
        <td><CategoryChips values={personnel.categories} /></td>
        <td className="dashActionsCell">{hoursControl}</td>
        <td>{personnel.tasks.length}</td>
        <td className="dashActionsCell">
          <span className="dashActionsRow">{actions}</span>
        </td>
      </tr>
      {error && (
        <tr><td colSpan={6}><div className="ui-error">{error}</div></td></tr>
      )}
      {expanded && (
        <tr>
          <td colSpan={6} className="dashExpandPanel">{panel}</td>
        </tr>
      )}
    </>
  );
}

function RequestRow({ application, onChanged }) {
  const confirm = useConfirm();
  const isMobile = useIsMobile();
  const categories = useCategories();
  const [expanded, setExpanded] = useState(false);
  const [notes, setNotes] = useState(application.admin_notes || '');
  const [error, setError] = useState('');
  const isUnread = !application.read_at;

  const decide = async (status) => {
    const approving = status === 'approved';
    const ok = await confirm({
      title: approving ? 'Approve this volunteer application?' : 'Reject this volunteer application?',
      message: approving
        ? 'The applicant is notified and becomes a volunteer with dashboard access.'
        : 'The applicant is notified that their application was not accepted, along with your notes.',
      confirmLabel: approving ? 'Approve' : 'Reject application',
      tone: approving ? 'default' : 'danger',
      summary: [
        { label: 'Applicant', value: application.applicant?.full_name },
        { label: 'Email', value: application.applicant?.email },
      ],
    });
    if (!ok) return;
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

  // The row opens the application; the decision is taken inside. That also closes a gap: the
  // notes textarea below is what decide() sends as admin_notes, and it used to be possible to
  // approve from the row without that field ever having been on screen.
  const actions = (
    <button className="dashBtn" onClick={toggleDetails}>{expanded ? 'Hide' : 'View details'}</button>
  );

  const panel = (
    <div className="dashReviewCard">
      {error && <div className="ui-error">{error}</div>}
      <div className="dashReviewSection">
        <div className="dashReviewSectionTitle">Applicant</div>
        <dl className="dashInfoList">
          <div><dt>Phone</dt><dd>{application.applicant?.phone || '—'}</dd></div>
          <div className="dashInfoFull"><dt>Experience</dt><dd>{application.experience || '—'}</dd></div>
          <div className="dashInfoFull"><dt>Why volunteer?</dt><dd>{application.reason || '—'}</dd></div>
          <div className="dashInfoFull"><dt>Would like to help with</dt><dd><CategoryChips values={application.preferred_categories} /></dd></div>
        </dl>
      </div>
      <div className="dashReviewSection">
        <div className="dashReviewSectionTitle">Valid ID</div>
        <dl className="dashInfoList">
          <div><dt>ID type</dt><dd>{application.valid_id_type ? optionLabel(categories.valid_id_type, application.valid_id_type) : '—'}</dd></div>
        </dl>
        {application.valid_id_url ? (
          <a href={fileSrc(application.valid_id_url)} target="_blank" rel="noreferrer" title="Open the full-size ID">
            <img
              src={fileSrc(application.valid_id_url)}
              alt={`Valid ID submitted by ${application.applicant?.full_name || 'the applicant'}`}
              style={{ maxWidth: 'min(320px, 100%)', marginTop: 10, borderRadius: 8, border: '1px solid var(--line)', display: 'block' }}
            />
          </a>
        ) : (
          <div className="ui-muted" style={{ marginTop: 10, fontSize: '0.85rem' }}>
            No ID photo on file — this application predates the ID requirement.
          </div>
        )}
      </div>
      <div className="dashReviewSection">
        <div className="dashReviewSectionTitle">Decision notes</div>
        <div className="ui-field">
          <label className="ui-label">Shared with the applicant on decision</label>
          <textarea className="ui-input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
      </div>
      {application.status === 'pending' && (
        <div className="dashActionRow">
          <ActionMenu
            label="Decide"
            ariaLabel={`Decide on ${application.applicant?.full_name || 'this'} application`}
            items={[
              { label: 'Approve', tone: 'primary', onClick: () => decide('approved') },
              { divider: true },
              { label: 'Reject', tone: 'danger', onClick: () => decide('rejected') },
            ]}
          />
        </div>
      )}
    </div>
  );

  if (isMobile) {
    return (
      <>
        <DashCard
          accent={isUnread ? 'unread' : undefined}
          title={application.applicant?.full_name || '—'}
          subtitle={application.applicant?.email}
          fields={[
            { label: 'Availability', value: application.availability || '—' },
            { label: 'Status', value: <StatusBadge status={application.status} /> },
            { label: 'Submitted', value: (application.created_at || '').slice(0, 10) },
          ]}
          actions={actions}
        />
        {expanded && <div className="dashCardExpand">{panel}</div>}
      </>
    );
  }

  return (
    <>
      <tr className={isUnread ? 'dashRowUnread' : ''}>
        <td>{application.applicant?.full_name || '—'}<br /><span style={{ fontSize: 12, color: 'var(--muted)' }}>{application.applicant?.email}</span></td>
        <td>{application.availability || '—'}</td>
        <td><StatusBadge status={application.status} /></td>
        <td>{(application.created_at || '').slice(0, 10)}</td>
        <td className="dashActionsCell">
          <span className="dashActionsRow">{actions}</span>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={5} className="dashExpandPanel">{panel}</td>
        </tr>
      )}
    </>
  );
}

function VolunteerRequests() {
  const isMobile = useIsMobile();
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
      ) : isMobile ? (
        <div className="dashCardList">
          {applications.map((a) => (
            <RequestRow key={a.id} application={a} onChanged={refresh} />
          ))}
        </div>
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

function PersonnelRoster({ type, canSupervise }) {
  const isMobile = useIsMobile();
  const [personnel, setPersonnel] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [showAdd, setShowAdd] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
  const [category, setCategory] = useState('');
  const lists = useCategories();

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListVolunteers({ type, page, category })
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
  }, [type, refreshKey, page, category]);

  // Keep the current page on refresh so an open Tasks panel isn't collapsed.
  const refresh = () => {
    setShowAdd(false);
    setRefreshKey((k) => k + 1);
  };

  const typeLabel = type === 'staff' ? 'staff' : 'volunteers';
  // Making someone staff is a role change, so it's the System Admin's (Users → Role); the desk
  // only adds volunteers here.
  const canAdd = type !== 'staff';

  return (
    <>
      <style>{CATEGORY_STYLES}</style>
      {error && <div className="ui-error">{error}</div>}

      <div className="dashFilterBar">
        {canAdd ? (
          <button className="dashBtn dashBtnPrimary" onClick={() => setShowAdd((v) => !v)}>
            {showAdd ? 'Close' : '+ Add volunteer'}
          </button>
        ) : (
          <span className="ui-muted" style={{ fontSize: '0.9rem' }}>Staff are added by a System Admin, from Users.</span>
        )}
        <select className="ui-input" style={{ maxWidth: 230 }} aria-label={`Filter ${typeLabel} by category`} value={category} onChange={(e) => { setPage(1); setCategory(e.target.value); }}>
          <option value="">All categories</option>
          {(lists.volunteer_category || []).map((o) => <option key={o.value} value={o.value}>{o.label}{o.hidden ? ' (hidden)' : ''}</option>)}
        </select>
      </div>

      {canAdd && showAdd && <AddPersonForm type={type} onCancel={() => setShowAdd(false)} onAdded={refresh} />}

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : personnel.length === 0 ? (
        <div className="ui-empty">{category ? `No ${typeLabel} in this category.` : `No ${typeLabel} yet.`}</div>
      ) : isMobile ? (
        <div className="dashCardList" style={{ marginTop: 10 }}>
          {personnel.map((p) => (
            <PersonnelRow key={p.id} personnel={p} onChanged={refresh} canSupervise={canSupervise} />
          ))}
        </div>
      ) : (
        <div className="dashTableWrap" style={{ marginTop: 10 }}>
          <table className="dashTable">
            <thead>
              <tr>
                <th>{type === 'staff' ? 'Staff Member' : 'Volunteer'}</th>
                <th>Availability</th>
                <th>Categories</th>
                <th>Hours rendered</th>
                <th>Tasks</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {personnel.map((p) => (
                <PersonnelRow key={p.id} personnel={p} onChanged={refresh} canSupervise={canSupervise} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && personnel.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}

export default function VolunteersAdmin({ canSupervise = false }) {
  const [mode, setMode] = useState('volunteers');
  const [subMode, setSubMode] = useState('roster');

  return (
    <>
      <h2 className="dashSectionTitle"><Users size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Personnel Management</h2>

      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        <button
          className={mode === 'volunteers' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
          onClick={() => setMode('volunteers')}
        >
          <Handshake size={16} style={{ verticalAlign: '-3px' }} /> Volunteers
        </button>
        <button
          className={mode === 'staff' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
          onClick={() => setMode('staff')}
        >
          <Briefcase size={16} style={{ verticalAlign: '-3px' }} /> Staff
        </button>
        <button
          className={mode === 'attendance' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
          onClick={() => setMode('attendance')}
        >
          <Clock size={16} style={{ verticalAlign: '-3px' }} /> Attendance
        </button>
        <button
          className={mode === 'teams' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
          onClick={() => setMode('teams')}
        >
          <UsersRound size={16} style={{ verticalAlign: '-3px' }} /> Teams
        </button>
      </div>

      {mode === 'volunteers' ? (
        <>
          <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
            <button
              className={subMode === 'roster' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
              onClick={() => setSubMode('roster')}
            >
              <ClipboardList size={16} style={{ verticalAlign: '-3px' }} /> Volunteers
            </button>
            <button
              className={subMode === 'requests' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
              onClick={() => setSubMode('requests')}
            >
              <Inbox size={16} style={{ verticalAlign: '-3px' }} /> Requests
            </button>
          </div>

          {subMode === 'requests' ? (
            <VolunteerRequests />
          ) : (
            <PersonnelRoster type="volunteer" canSupervise={canSupervise} />
          )}
        </>
      ) : mode === 'attendance' ? (
        <AttendanceAdmin canCorrect={canSupervise} />
      ) : mode === 'teams' ? (
        <TeamsAdmin />
      ) : (
        <PersonnelRoster type="staff" canSupervise={canSupervise} />
      )}
    </>
  );
}
