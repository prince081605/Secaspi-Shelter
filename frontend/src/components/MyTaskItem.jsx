import { useEffect, useState } from 'react';
import { requestVolunteerTask, submitTaskProof } from '../lib/volunteersApi';
import StatusBadge from './StatusBadge';
import PhotoInput from './PhotoInput';

const styles = `
  .myTaskList { list-style: none; padding: 0; margin: 0; }
  .myTask { display: flex; flex-direction: column; gap: 0.8rem; padding: 0.9rem 1.1rem; border: 1px solid var(--line); border-radius: 12px; margin-bottom: 0.7rem; background: var(--surface, transparent); }
  .myTaskHead { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
  .myTaskName { font-weight: 600; }
  .myTaskMeta { font-size: 0.82rem; color: var(--muted); margin-top: 0.2rem; }
  .myTaskActions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .myTaskPanel { border-top: 1px solid var(--line); padding-top: 0.9rem; display: grid; gap: 1rem; }
  .myTaskPanelTitle { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin: 0 0 0.5rem; }
  .myTaskDetails { display: grid; grid-template-columns: max-content 1fr; gap: 0.35rem 1rem; margin: 0; font-size: 0.9rem; }
  .myTaskDetails dt { color: var(--muted); }
  .myTaskDetails dd { margin: 0; overflow-wrap: anywhere; }
  .myTaskProof { display: block; width: 160px; max-width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; border: 1px solid var(--line); }
  .myTaskPreview { display: block; width: 120px; max-width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; border: 1px solid var(--line); margin-top: 0.6rem; }
  .myTaskVerified { padding: 0.6rem 0.8rem; border-radius: 8px; background: #d8f3dc; color: #1b7a3d; font-size: 0.88rem; }
  @media (max-width: 560px) {
    .myTaskDetails { grid-template-columns: 1fr; gap: 0.1rem; }
    .myTaskDetails dd { margin-bottom: 0.5rem; }
  }
`;

// A 'requested' task has not been approved yet and a 'completed' one is closed. Everything in
// between is work the person can report on, including 'submitted', so a blurry photo can be
// replaced while it is still waiting for an admin.
const CAN_SEND_PROOF = ['assigned', 'ongoing', 'submitted'];

const STATUS_LINE = {
  requested: 'Awaiting confirmation',
  submitted: 'Proof sent — waiting for an admin to verify',
  completed: 'Verified complete',
};

function formatDateTime(value) {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

// Today in the viewer's own timezone, as YYYY-MM-DD for the date input's `min`.
function todayLocal() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * A volunteer or staff member proposes a task, optionally with the day they plan to do it. It
 * lands as 'requested' until the team confirms it.
 */
export function RequestTaskForm({ onRequested }) {
  const [taskName, setTaskName] = useState('');
  const [date, setDate] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const submit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');
    try {
      await requestVolunteerTask({ task_name: taskName, assigned_date: date || null });
      setTaskName('');
      setDate('');
      onRequested?.();
    } catch (err) {
      const fieldError = Object.values(err?.data?.errors || {})[0]?.[0];
      setError(fieldError || err?.message || 'Failed to request task. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form onSubmit={submit} style={{ marginBottom: 16 }}>
      {error && <div className="ui-error">{error}</div>}
      <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap' }}>
        <div className="ui-field" style={{ flex: '1 1 220px', marginBottom: 0 }}>
          <label className="ui-label ui-label-required">Task you'd like to do</label>
          <input
            className="ui-input"
            value={taskName}
            onChange={(e) => setTaskName(e.target.value)}
            placeholder="e.g. Walk the dogs"
            maxLength={150}
            required
          />
        </div>
        <div className="ui-field" style={{ flex: '0 1 170px', marginBottom: 0 }}>
          <label className="ui-label">Date (optional)</label>
          <input className="ui-input" type="date" min={todayLocal()} value={date} onChange={(e) => setDate(e.target.value)} />
        </div>
        <button className="ui-btn-primary" type="submit" disabled={submitting || !taskName.trim()}>
          {submitting ? 'Sending…' : 'Request task'}
        </button>
      </div>
    </form>
  );
}

export function MyTaskList({ children }) {
  return (
    <>
      <style>{styles}</style>
      <ul className="myTaskList">{children}</ul>
    </>
  );
}

/**
 * One of the signed-in volunteer's or staff member's own tasks. "Update" opens the task's
 * completion details (the proof photo, when it was sent, and who verified it) and,
 * while the task is still open, the form to send or replace that proof. Sending proof never
 * completes the task — it parks it as 'submitted' until an admin verifies it.
 */
export default function MyTaskItem({ task, onUpdated }) {
  const [open, setOpen] = useState(false);
  const [image, setImage] = useState(null);
  const [previewUrl, setPreviewUrl] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);

  const canSend = CAN_SEND_PROOF.includes(task.status);
  const hasProof = Boolean(task.proof_url);

  // Release the previous preview when a new file is picked, and the last one on unmount.
  useEffect(() => () => { if (previewUrl) URL.revokeObjectURL(previewUrl); }, [previewUrl]);

  const choose = ([file = null]) => {
    setImage(file);
    setPreviewUrl(file ? URL.createObjectURL(file) : '');
  };

  const send = async (e) => {
    e.preventDefault();
    if (!image) return;
    setSending(true);
    setError('');
    try {
      const body = new FormData();
      body.append('proof_image', image);
      await submitTaskProof(task.id, body);
      setImage(null);
      setPreviewUrl('');
      setSent(true);
      onUpdated?.();
    } catch (err) {
      // Laravel's 422 carries the useful sentence in `errors`, not `message`.
      const fieldError = Object.values(err?.data?.errors || {})[0]?.[0];
      setError(fieldError || err?.message || 'Failed to send your proof. Please try again.');
    } finally {
      setSending(false);
    }
  };

  const toggle = () => {
    setOpen((v) => !v);
    setError('');
    setSent(false);
  };

  return (
    <li className="myTask">
      <div className="myTaskHead">
        <div>
          <div className="myTaskName">{task.task_name}</div>
          <div className="myTaskMeta">{[STATUS_LINE[task.status], task.assigned_date && `Scheduled ${task.assigned_date}`].filter(Boolean).join(' · ')}</div>
        </div>
        <div className="myTaskActions">
          <StatusBadge status={task.status} />
          {task.status !== 'requested' && (
            <button type="button" className="dashBtn dashBtnPrimary" onClick={toggle} aria-expanded={open}>
              {open ? 'Close' : 'Update'}
            </button>
          )}
        </div>
      </div>

      {open && (
        <div className="myTaskPanel">
          <section>
            <h3 className="myTaskPanelTitle">Completion details</h3>
            {task.status === 'completed' && (
              <div className="myTaskVerified" style={{ marginBottom: '0.7rem' }}>
                Verified by {task.verified_by || 'an admin'} on {formatDateTime(task.verified_at)}.
              </div>
            )}
            {sent && <div className="ui-notice">Proof sent. An admin will verify it and complete the task.</div>}
            <dl className="myTaskDetails">
              <dt>Status</dt><dd><StatusBadge status={task.status} /></dd>
              <dt>Scheduled</dt><dd>{task.assigned_date || '—'}</dd>
              <dt>Proof sent</dt><dd>{hasProof ? formatDateTime(task.proof_submitted_at) : 'Not yet'}</dd>
            </dl>
            {hasProof && (
              <a href={task.proof_url} target="_blank" rel="noreferrer" title="Open the full-size photo" style={{ display: 'inline-block', marginTop: '0.7rem' }}>
                <img src={task.proof_url} alt={`Proof sent for ${task.task_name}`} className="myTaskProof" />
              </a>
            )}
          </section>

          {canSend && (
            <form onSubmit={send}>
              <h3 className="myTaskPanelTitle">{hasProof ? 'Replace your proof' : 'Send proof of completion'}</h3>
              {error && <div className="ui-error">{error}</div>}
              <div className="ui-field">
                <label className="ui-label ui-label-required">Photo of the finished task</label>
                <PhotoInput files={image ? [image] : null} onChange={choose} required thumbnails={false} disabled={sending} cameraTitle="Photograph the finished task" />
                <p className="ui-muted" style={{ fontSize: '0.8rem', margin: '0.3rem 0 0' }}>JPG or PNG, up to 5 MB.</p>
                {previewUrl && <img src={previewUrl} alt="Your photo, as it will be sent" className="myTaskPreview" />}
              </div>
              <button className="ui-btn-primary" type="submit" disabled={sending || !image}>
                {sending ? 'Sending…' : 'Submit for verification'}
              </button>
            </form>
          )}
        </div>
      )}
    </li>
  );
}
