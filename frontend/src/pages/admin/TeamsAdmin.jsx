import { useEffect, useState } from 'react';
import { Archive, ArchiveRestore, Crown, ListChecks, Plus, Siren, UserMinus, UsersRound } from 'lucide-react';
import {
  adminAddTeamMember,
  adminAssignTeamTask,
  adminCreateTeam,
  adminGetTeam,
  adminListTeams,
  adminListVolunteers,
  adminRemoveTeamMember,
  adminUpdateTeam,
} from '../../lib/volunteersApi';
import StatusBadge from '../../components/StatusBadge';
import useConfirm from '../../lib/useConfirm';
import './Teams.css';

const errorText = (err, fallback) => Object.values(err?.data?.errors || {})[0]?.[0] || err?.message || fallback;
const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

function NewTeamForm({ onCreated }) {
  const [name, setName] = useState('');
  const [purpose, setPurpose] = useState('');
  const [state, setState] = useState({ saving: false, error: '' });

  const submit = async (e) => {
    e.preventDefault();
    setState({ saving: true, error: '' });
    try {
      const res = await adminCreateTeam({ name, purpose: purpose || null });
      setName('');
      setPurpose('');
      setState({ saving: false, error: '' });
      onCreated(res.team);
    } catch (err) {
      setState({ saving: false, error: errorText(err, 'The team could not be created.') });
    }
  };

  return (
    <form className="dashCard teams-new" onSubmit={submit}>
      <h3>New team</h3>
      {state.error && <div className="ui-error">{state.error}</div>}
      <input className="ui-input" maxLength={100} required placeholder="Team name, e.g. Rescue Team Alpha" aria-label="Team name" value={name} onChange={(e) => setName(e.target.value)} />
      <input className="ui-input" maxLength={1000} placeholder="What the team does (optional)" aria-label="Team purpose" value={purpose} onChange={(e) => setPurpose(e.target.value)} />
      <button className="dashBtn dashBtnPrimary" type="submit" disabled={state.saving || !name.trim()}>
        <Plus size={15} aria-hidden="true" /> {state.saving ? 'Creating…' : 'Create team'}
      </button>
    </form>
  );
}

function TeamDetail({ teamId, personnel, onChanged }) {
  const confirm = useConfirm();
  const [team, setTeam] = useState(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(null); // { name, purpose }
  const [newMember, setNewMember] = useState('');
  const [task, setTask] = useState({ task_name: '', assigned_date: '' });

  useEffect(() => {
    let alive = true;
    adminGetTeam(teamId)
      .then((res) => { if (alive) setTeam(res.team); })
      .catch((err) => { if (alive) setError(err?.message || 'Could not load the team.'); });
    return () => { alive = false; };
  }, [teamId]);

  // Every change returns the updated team; show it and refresh the list's numbers.
  const run = async (action, success) => {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const res = await action();
      if (res?.team) setTeam(res.team);
      setNotice(success);
      onChanged();
      return true;
    } catch (err) {
      setError(errorText(err, 'That didn’t work. Please try again.'));
      return false;
    } finally {
      setBusy(false);
    }
  };

  if (!team) return <div className="dashCard teams-detail">{error ? <div className="ui-error">{error}</div> : <div className="ui-empty">Loading…</div>}</div>;

  const memberIds = new Set(team.members.map((m) => m.id));
  const candidates = personnel.filter((p) => !memberIds.has(p.id));

  const saveDetails = async (e) => {
    e.preventDefault();
    if (await run(() => adminUpdateTeam(team.id, { name: editing.name, purpose: editing.purpose || null }), 'Team details saved.')) setEditing(null);
  };

  const toggleArchive = async () => {
    if (team.is_active) {
      const ok = await confirm({
        title: `Archive ${team.name}?`,
        message: 'It stops appearing when assigning rescues and can’t be given new tasks. Its members, rescues and task history are kept, and you can restore it any time.',
        confirmLabel: 'Archive team',
      });
      if (!ok) return;
    }
    run(() => adminUpdateTeam(team.id, { is_active: !team.is_active }), team.is_active ? 'Team archived.' : 'Team restored.');
  };

  const addMember = async (e) => {
    e.preventDefault();
    if (!newMember) return;
    if (await run(() => adminAddTeamMember(team.id, Number(newMember)), 'Member added.')) setNewMember('');
  };

  const removeMember = async (m) => {
    const ok = await confirm({
      title: `Take ${m.full_name} off ${team.name}?`,
      message: m.is_leader ? 'They lead this team, so it will have no leader until you choose another.' : 'Tasks they already have from the team stay with them.',
      confirmLabel: 'Remove from team',
    });
    if (!ok) return;
    run(() => adminRemoveTeamMember(team.id, m.id), `${m.full_name} was taken off the team.`);
  };

  const assignTask = async (e) => {
    e.preventDefault();
    const ok = await confirm({
      title: `Give “${task.task_name}” to ${team.name}?`,
      message: `Each of the ${plural(team.members.length, 'member')} gets their own copy and a notification, and sends their own proof when it’s done.`,
      confirmLabel: `Assign to ${plural(team.members.length, 'member')}`,
    });
    if (!ok) return;
    if (await run(() => adminAssignTeamTask(team.id, { task_name: task.task_name, assigned_date: task.assigned_date || null }), 'Task assigned to the team.')) {
      setTask({ task_name: '', assigned_date: '' });
    }
  };

  return (
    <div className="dashCard teams-detail">
      <div className="teams-detail-head">
        {editing ? (
          <form className="teams-edit" onSubmit={saveDetails}>
            <input className="ui-input" maxLength={100} required aria-label="Team name" value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} />
            <input className="ui-input" maxLength={1000} placeholder="What the team does" aria-label="Team purpose" value={editing.purpose} onChange={(e) => setEditing({ ...editing, purpose: e.target.value })} />
            <div className="teams-actions">
              <button className="dashBtn dashBtnPrimary" type="submit" disabled={busy}>Save</button>
              <button className="dashBtn" type="button" onClick={() => setEditing(null)}>Cancel</button>
            </div>
          </form>
        ) : (
          <div>
            <h3>{team.name} {!team.is_active && <span className="badge badgeOrange">Archived</span>}</h3>
            {team.purpose && <p className="ui-muted">{team.purpose}</p>}
          </div>
        )}
        {!editing && (
          <div className="teams-actions">
            <button className="dashBtn" type="button" onClick={() => setEditing({ name: team.name, purpose: team.purpose || '' })}>Edit</button>
            <button className="dashBtn" type="button" disabled={busy} onClick={toggleArchive}>
              {team.is_active ? <><Archive size={14} aria-hidden="true" /> Archive</> : <><ArchiveRestore size={14} aria-hidden="true" /> Restore</>}
            </button>
          </div>
        )}
      </div>

      {error && <div className="ui-error" role="alert">{error}</div>}
      {notice && <div className="ui-success-msg" role="status">{notice}</div>}

      <section className="teams-section">
        <h4><UsersRound size={15} aria-hidden="true" /> Members ({team.members.length})</h4>
        {team.members.length === 0 ? (
          <p className="ui-muted">No members yet. Add staff and volunteers below.</p>
        ) : (
          <ul className="teams-members">
            {team.members.map((m) => (
              <li key={m.id}>
                <div>
                  <span className="name">{m.full_name}</span>
                  <span className={`badge ${m.type === 'staff' ? 'badgeSky' : 'badgeGreen'}`}>{m.type}</span>
                  {m.is_leader && <span className="teams-leader"><Crown size={13} aria-hidden="true" /> Leader</span>}
                  <div className="ui-muted contact">{[m.phone, m.email].filter(Boolean).join(' · ')}</div>
                </div>
                <div className="teams-actions">
                  {!m.is_leader && team.is_active && (
                    <button className="dashBtn" type="button" disabled={busy} onClick={() => run(() => adminUpdateTeam(team.id, { leader_id: m.id }), `${m.full_name} now leads the team.`)}>
                      <Crown size={14} aria-hidden="true" /> Make leader
                    </button>
                  )}
                  <button className="dashBtn dashBtnDanger" type="button" disabled={busy} onClick={() => removeMember(m)} aria-label={`Remove ${m.full_name} from the team`}>
                    <UserMinus size={14} aria-hidden="true" />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
        {team.is_active && (
          <form className="teams-inline" onSubmit={addMember}>
            <select className="ui-input" aria-label="Person to add" value={newMember} onChange={(e) => setNewMember(e.target.value)}>
              <option value="">{candidates.length ? 'Add someone from Personnel…' : 'Everyone in Personnel is on this team'}</option>
              {candidates.map((p) => <option key={p.id} value={p.id}>{p.user?.full_name || `Personnel #${p.id}`} ({p.type})</option>)}
            </select>
            <button className="dashBtn dashBtnPrimary" type="submit" disabled={busy || !newMember}><Plus size={15} aria-hidden="true" /> Add</button>
          </form>
        )}
      </section>

      <section className="teams-section">
        <h4><ListChecks size={15} aria-hidden="true" /> Team tasks</h4>
        {team.is_active && team.members.length > 0 && (
          <form className="teams-inline" onSubmit={assignTask}>
            <input className="ui-input" maxLength={150} required placeholder="Give the whole team a task, e.g. Clean the kennels" aria-label="Team task" value={task.task_name} onChange={(e) => setTask({ ...task, task_name: e.target.value })} />
            <input className="ui-input teams-date" type="date" aria-label="Task date" value={task.assigned_date} onChange={(e) => setTask({ ...task, assigned_date: e.target.value })} />
            <button className="dashBtn dashBtnPrimary" type="submit" disabled={busy || !task.task_name.trim()}>Assign</button>
          </form>
        )}
        {team.tasks.length === 0 ? (
          <p className="ui-muted">No team tasks yet.</p>
        ) : (
          <ul className="teams-tasks">
            {team.tasks.map((t) => (
              <li key={t.batch}>
                <div className="teams-task-head">
                  <span className="name">{t.task_name}</span>
                  <span className="ui-muted">{t.assigned_date || 'No date'}</span>
                </div>
                <div className="teams-progress" role="img" aria-label={`${t.completed} of ${t.total} done`}>
                  <span style={{ width: `${t.total ? (t.completed / t.total) * 100 : 0}%` }} />
                </div>
                <div className="ui-muted teams-task-foot">
                  {t.completed} of {t.total} done{t.submitted ? ` · ${t.submitted} waiting for sign-off` : ''}
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="teams-section">
        <h4><Siren size={15} aria-hidden="true" /> Rescues assigned</h4>
        {team.rescues.length === 0 ? (
          <p className="ui-muted">None yet. Assign a rescue to this team from Rescue Reports.</p>
        ) : (
          <ul className="teams-rescues">
            {team.rescues.map((r) => (
              <li key={r.id}>
                <span>{r.location}</span>
                <span className="ui-muted">urgency: {r.urgency}</span>
                <StatusBadge status={r.status} />
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

/**
 * Personnel → Teams: teams of staff and volunteers, each with a leader. Rescue reports can be
 * assigned to a team (from Rescue Reports), and a task can be given to a whole team here.
 */
export default function TeamsAdmin() {
  const [teams, setTeams] = useState(null);
  const [personnel, setPersonnel] = useState([]);
  const [selected, setSelected] = useState(null);
  const [showArchived, setShowArchived] = useState(false);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let alive = true;
    adminListTeams()
      .then((res) => { if (alive) setTeams(res.teams || []); })
      .catch((err) => { if (alive) setError(err?.message || 'Could not load teams.'); });
    return () => { alive = false; };
  }, [refreshKey]);

  // Everyone in Personnel (staff and volunteers), for adding members.
  useEffect(() => {
    let alive = true;
    Promise.all([adminListVolunteers({ type: 'volunteer', per_page: 100 }), adminListVolunteers({ type: 'staff', per_page: 100 })])
      .then(([v, s]) => { if (alive) setPersonnel([...(s?.data || []), ...(v?.data || [])]); })
      .catch(() => {});
    return () => { alive = false; };
  }, []);

  const refresh = () => setRefreshKey((k) => k + 1);
  const shown = (teams || []).filter((t) => showArchived || t.is_active);
  const archivedCount = (teams || []).filter((t) => !t.is_active).length;

  return (
    <div className="teams-module">
      <p className="ui-muted teams-intro">
        Group staff and volunteers into teams with a leader. Assign rescues to a team from Rescue Reports — its members are notified — or
        give the whole team a task here.
      </p>
      {error && <div className="ui-error">{error}</div>}
      <div className="teams-layout">
        <div className="teams-side">
          <NewTeamForm onCreated={(team) => { setSelected(team.id); refresh(); }} />
          {teams === null ? (
            <div className="ui-empty">Loading…</div>
          ) : shown.length === 0 ? (
            <div className="ui-empty">No teams yet. Create the first one above.</div>
          ) : (
            <ul className="teams-list">
              {shown.map((t) => (
                <li key={t.id}>
                  <button type="button" className={`teams-card${selected === t.id ? ' is-selected' : ''}${t.is_active ? '' : ' is-archived'}`} onClick={() => setSelected(t.id)} aria-pressed={selected === t.id}>
                    <span className="name">{t.name}{!t.is_active && <span className="badge badgeOrange">Archived</span>}</span>
                    <span className="ui-muted meta">
                      {plural(t.members_count, 'member')}{t.leader ? ` · Leader: ${t.leader}` : ' · No leader yet'}
                    </span>
                    {(t.open_rescues_count > 0 || t.open_tasks_count > 0) && (
                      <span className="meta-strong">
                        {[t.open_rescues_count && plural(t.open_rescues_count, 'open rescue'), t.open_tasks_count && plural(t.open_tasks_count, 'open task')].filter(Boolean).join(' · ')}
                      </span>
                    )}
                  </button>
                </li>
              ))}
            </ul>
          )}
          {archivedCount > 0 && (
            <label className="teams-archived-toggle">
              <input type="checkbox" checked={showArchived} onChange={(e) => setShowArchived(e.target.checked)} /> Show archived teams ({archivedCount})
            </label>
          )}
        </div>
        <div className="teams-main">
          {selected ? (
            <TeamDetail key={selected} teamId={selected} personnel={personnel} onChanged={refresh} />
          ) : (
            <div className="ui-empty">Choose a team to see its members, tasks and rescues.</div>
          )}
        </div>
      </div>
    </div>
  );
}
