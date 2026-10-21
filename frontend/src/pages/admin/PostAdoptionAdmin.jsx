import { useEffect, useRef, useState } from 'react';
import { HeartHandshake, PawPrint } from 'lucide-react';
import {
  ADOPTER_WELLBEING,
  CONTACT_METHODS,
  POST_ADOPTION_STATUSES,
  RETURN_DESTINATIONS,
  STAFF_WELLBEING,
  adminListAdoptionUpdates,
  adminListAdoptions,
  adminListCheckIns,
  adminListReturns,
  adminMarkAdoptionUpdateRead,
  adminPostAdoptionSummary,
  adminRecordCheckIn,
  adminResolveReturn,
  adminReviewStory,
  adminSetAdoptionStatus,
  labelOf,
} from '../../lib/postAdoptionApi';
import Pagination from '../../components/Pagination';
import StatusBadge from '../../components/StatusBadge';
import useConfirm from '../../lib/useConfirm';
import './PostAdoption.css';

const formatDate = (value) => (value ? new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—');
// Today in the shelter's own day (Manila), for date inputs that can't be in the future.
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
const errorText = (err, fallback) => Object.values(err?.data?.errors || {})[0]?.[0] || err?.message || fallback;

/**
 * A paginated list from one of the post-adoption endpoints. `source` names what is being listed
 * (e.g. "check-ins:due"); the list reloads when it changes, on page change, and on reload().
 */
function usePaged(load, source) {
  const [data, setData] = useState(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  // The latest loader, so the effect can depend on `source` rather than a function rebuilt each render.
  const loadRef = useRef(load);
  useEffect(() => { loadRef.current = load; });

  useEffect(() => {
    let alive = true;
    loadRef.current(page)
      .then((res) => { if (alive) { setData(res); setError(''); } })
      .catch((err) => { if (alive) setError(err?.message || 'Could not load this list.'); });
    return () => { alive = false; };
  }, [page, refreshKey, source]);

  return {
    raw: data,
    items: data?.data || [],
    meta: { current_page: data?.current_page || 1, last_page: data?.last_page || 1 },
    loading: !data && !error,
    error,
    setPage,
    reload: () => setRefreshKey((k) => k + 1),
  };
}

function PetLine({ animal, adoption, extra }) {
  return (
    <div className="pa-pet-line">
      <div className="pa-pet-photo small">{animal?.photo ? <img src={animal.photo} alt="" /> : <PawPrint size={18} aria-hidden="true" />}</div>
      <div>
        <div className="pa-pet-name">{animal?.name || 'Animal'}</div>
        <div className="ui-muted pa-sub">
          Adopted by {adoption?.adopter || '—'}{adoption?.contact_number ? ` · ${adoption.contact_number}` : ''}
          {adoption?.completed_at ? ` · ${formatDate(adoption.completed_at)}` : ''}{extra ? ` · ${extra}` : ''}
        </div>
      </div>
    </div>
  );
}

// ---------- Check-ins ----------

function RecordCheckIn({ checkIn, onSaved, onCancel }) {
  const [form, setForm] = useState({ contact_method: 'call', wellbeing: '', notes: '' });
  const [state, setState] = useState({ saving: false, error: '' });
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setState({ saving: true, error: '' });
    try {
      await adminRecordCheckIn(checkIn.id, form);
      onSaved();
    } catch (err) {
      setState({ saving: false, error: errorText(err, 'The check-in could not be saved.') });
    }
  };

  return (
    <form className="pa-form pa-inline-form" onSubmit={submit}>
      {state.error && <div className="ui-error">{state.error}</div>}
      <div className="pa-form-row">
        <label>
          <span className="ui-label ui-label-required">How you reached them</span>
          <select className="ui-input" value={form.contact_method} onChange={set('contact_method')}>
            {CONTACT_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </select>
        </label>
        <label>
          <span className="ui-label ui-label-required">How {checkIn.animal?.name || 'the animal'} is doing</span>
          <select className="ui-input" value={form.wellbeing} onChange={set('wellbeing')} required>
            <option value="">Choose…</option>
            {STAFF_WELLBEING.map((w) => <option key={w.value} value={w.value}>{w.label}</option>)}
          </select>
        </label>
      </div>
      <label>
        <span className="ui-label">Notes</span>
        <textarea className="ui-textarea" rows={2} maxLength={2000} value={form.notes} onChange={set('notes')} placeholder="Eating, health, behaviour, anything the family asked about…" />
      </label>
      <div className="pa-actions">
        <button className="dashBtn dashBtnPrimary" type="submit" disabled={state.saving}>{state.saving ? 'Saving…' : 'Save check-in'}</button>
        <button className="dashBtn" type="button" onClick={onCancel} disabled={state.saving}>Cancel</button>
      </div>
    </form>
  );
}

function CheckInsTab({ onChanged }) {
  const [view, setView] = useState('due');
  const [recording, setRecording] = useState(null);
  const list = usePaged((page) => adminListCheckIns(view, page), `check-ins:${view}`);

  return (
    <>
      <div className="pa-chips" role="group" aria-label="Which check-ins">
        {[['due', 'Due now'], ['upcoming', 'Upcoming'], ['done', 'Done']].map(([key, label]) => (
          <button key={key} type="button" className={view === key ? 'active' : ''} aria-pressed={view === key} onClick={() => { setView(key); list.setPage(1); setRecording(null); }}>{label}</button>
        ))}
      </div>
      {list.error && <div className="ui-error">{list.error}</div>}
      {list.loading ? <div className="ui-empty">Loading…</div> : list.items.length === 0 ? (
        <div className="ui-empty">{view === 'due' ? 'No check-ins due. Every adopter is up to date.' : view === 'upcoming' ? 'No check-ins scheduled.' : 'No check-ins recorded yet.'}</div>
      ) : (
        <ul className="pa-list">
          {list.items.map((c) => (
            <li key={c.id} className="dashCard">
              <div className="pa-row">
                <PetLine animal={c.animal} adoption={c.adoption} />
                <div className="pa-row-side">
                  <div className="pa-due">
                    <strong>{c.label} check-in</strong>
                    <span className="ui-muted">{c.status === 'done' ? `Done ${formatDate(c.completed_at)}` : `Due ${formatDate(c.due_date)}`}</span>
                    {c.overdue && <span className="badge badgeOrange">Overdue</span>}
                  </div>
                  {c.status === 'pending' && recording !== c.id && (
                    <button className="dashBtn dashBtnPrimary" type="button" onClick={() => setRecording(c.id)}>Record check-in</button>
                  )}
                </div>
              </div>
              {c.status === 'done' && (
                <p className="pa-recorded">
                  <span className={`pa-feel pa-feel-${c.wellbeing}`}>{labelOf(STAFF_WELLBEING, c.wellbeing)}</span>
                  {' '}{labelOf(CONTACT_METHODS, c.contact_method)}{c.recorded_by ? ` · by ${c.recorded_by}` : ''}
                  {c.notes ? <><br />{c.notes}</> : null}
                </p>
              )}
              {recording === c.id && (
                <RecordCheckIn checkIn={c} onCancel={() => setRecording(null)} onSaved={() => { setRecording(null); list.reload(); onChanged(); }} />
              )}
            </li>
          ))}
        </ul>
      )}
      <Pagination meta={list.meta} onPage={list.setPage} />
    </>
  );
}

// ---------- Adopted animals: where each one is now ----------

const STATUS_NOTE_HINTS = {
  in_home: 'How you confirmed it — e.g. found by a neighbour, the family called back…',
  lost_contact: 'What you tried — calls, texts, the address on file…',
  missing: 'When and where the animal was last seen, and what the family is doing…',
  deceased: 'The cause if known, and how the family told us…',
  rehomed: 'Who has the animal now, and how to reach them if known…',
  returned: 'Who brought the animal back, and its condition…',
};

function UpdateStatus({ adoption, onSaved, onCancel }) {
  const confirm = useConfirm();
  const name = adoption.animal?.name || 'the animal';
  const current = adoption.post_adoption_status;
  // Deceased can't become Returned: if Deceased was a mistake, it goes back to In home first.
  const options = POST_ADOPTION_STATUSES.filter((o) => o.value !== current && !(current === 'deceased' && o.value === 'returned'));
  const [form, setForm] = useState({ status: '', happened_on: today(), animal_status: 'available', notes: '' });
  const [state, setState] = useState({ saving: false, error: '' });
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));
  const chosen = POST_ADOPTION_STATUSES.find((o) => o.value === form.status);

  const submit = async (e) => {
    e.preventDefault();
    if (!chosen) return;
    const ok = await confirm({
      title: `Mark ${name} as ${chosen.label}?`,
      message: chosen.effect,
      confirmLabel: 'Update status',
      tone: ['deceased', 'returned'].includes(form.status) ? 'danger' : 'default',
      summary: [
        { label: 'From', value: labelOf(POST_ADOPTION_STATUSES, current) },
        { label: 'To', value: chosen.label },
        { label: 'Date', value: formatDate(form.happened_on) },
        form.status === 'returned' && { label: 'Goes to', value: labelOf(RETURN_DESTINATIONS, form.animal_status) },
      ].filter(Boolean),
    });
    if (!ok) return;
    setState({ saving: true, error: '' });
    try {
      await adminSetAdoptionStatus(adoption.id, {
        status: form.status,
        happened_on: form.happened_on || null,
        animal_status: form.status === 'returned' ? form.animal_status : null,
        notes: form.notes.trim() || null,
      });
      onSaved();
    } catch (err) {
      setState({ saving: false, error: errorText(err, 'The status could not be updated.') });
    }
  };

  return (
    <form className="pa-form pa-inline-form" onSubmit={submit}>
      {state.error && <div className="ui-error">{state.error}</div>}
      <div className="pa-form-row">
        <label>
          <span className="ui-label ui-label-required">New status</span>
          <select className="ui-input" value={form.status} onChange={set('status')} required>
            <option value="">Choose…</option>
            {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </label>
        <label>
          <span className="ui-label">When it happened</span>
          <input
            className="ui-input"
            type="date"
            value={form.happened_on}
            onChange={set('happened_on')}
            max={today()}
            min={adoption.completed_at ? String(adoption.completed_at).slice(0, 10) : undefined}
          />
        </label>
      </div>
      {chosen && <p className="ui-muted pa-sub pa-effect">{chosen.effect}</p>}
      {form.status === 'returned' && (
        <label>
          <span className="ui-label ui-label-required">{name} goes</span>
          <select className="ui-input" value={form.animal_status} onChange={set('animal_status')}>
            {RETURN_DESTINATIONS.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
          </select>
        </label>
      )}
      <label>
        <span className="ui-label">Notes</span>
        <textarea className="ui-textarea" rows={2} maxLength={2000} value={form.notes} onChange={set('notes')} placeholder={STATUS_NOTE_HINTS[form.status] || 'What happened, and how you found out…'} />
      </label>
      <div className="pa-actions">
        <button className="dashBtn dashBtnPrimary" type="submit" disabled={state.saving || !form.status}>{state.saving ? 'Saving…' : 'Update status'}</button>
        <button className="dashBtn" type="button" onClick={onCancel} disabled={state.saving}>Cancel</button>
      </div>
    </form>
  );
}

function StatusTimeline({ history }) {
  return (
    <ol className="pa-timeline" aria-label="Status history">
      {history.map((h) => (
        <li key={h.id}>
          <div className="pa-timeline-head">
            <StatusBadge status={h.to} />
            <strong>{h.to_label}</strong>
            <span className="ui-muted">{h.happened_on ? formatDate(h.happened_on) : 'date not recorded'}</span>
            <span className="ui-muted">· {h.by_adopter ? `reported by the family (${h.by})` : h.by ? `by ${h.by}` : 'automatic'}</span>
          </div>
          {h.animal_status && <div className="ui-muted pa-sub">Went {labelOf(RETURN_DESTINATIONS, h.animal_status).toLowerCase()}.</div>}
          {h.notes && <p>{h.notes}</p>}
        </li>
      ))}
    </ol>
  );
}

function AdoptionsTab({ onChanged }) {
  const [status, setStatus] = useState('');
  const [typed, setTyped] = useState('');
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState({ id: null, panel: '' }); // panel: 'status' | 'history'
  const list = usePaged((page) => adminListAdoptions({ status, q: search, page }), `adoptions:${status}:${search}`);
  const { setPage } = list;
  const counts = list.raw?.counts || {};
  const total = Object.values(counts).reduce((sum, n) => sum + n, 0);

  // Search as the name is typed, once typing pauses.
  useEffect(() => {
    const t = setTimeout(() => { setSearch(typed.trim()); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [typed, setPage]);

  const toggle = (id, panel) => setOpen((o) => (o.id === id && o.panel === panel ? { id: null, panel: '' } : { id, panel }));

  return (
    <>
      <p className="ui-muted pa-tab-intro">
        Every adopted animal and where it is now. Update the status when you learn something has changed — each change is kept in the animal’s history.
      </p>
      <div className="pa-toolbar">
        <div className="pa-chips" role="group" aria-label="Filter by status">
          {[['', 'All', total], ...POST_ADOPTION_STATUSES.map((o) => [o.value, o.value === 'returned' ? 'Returned' : o.label, counts[o.value]])].map(([key, label, n]) => (
            <button key={key || 'all'} type="button" className={status === key ? 'active' : ''} aria-pressed={status === key} onClick={() => { setStatus(key); list.setPage(1); setOpen({ id: null, panel: '' }); }}>
              {label}{n ? ` (${n})` : ''}
            </button>
          ))}
        </div>
        <input className="ui-input pa-search" type="search" placeholder="Search animal, adopter or reference" aria-label="Search adopted animals" value={typed} onChange={(e) => setTyped(e.target.value)} />
      </div>
      {list.error && <div className="ui-error">{list.error}</div>}
      {list.loading ? <div className="ui-empty">Loading…</div> : list.items.length === 0 ? (
        <div className="ui-empty">{search || status ? 'No adopted animals match.' : 'No completed adoptions yet.'}</div>
      ) : (
        <ul className="pa-list">
          {list.items.map((a) => {
            const since = a.history[0]?.happened_on; // unknown for adoptions completed before dates were kept
            const closed = a.status === 'returned';
            return (
              <li key={a.id} className="dashCard">
                <div className="pa-row">
                  <PetLine animal={a.animal} adoption={a} extra={a.reference_no} />
                  <div className="pa-row-side">
                    <div className="pa-due">
                      <StatusBadge status={a.post_adoption_status} />
                      {since && <span className="ui-muted">since {formatDate(since)}</span>}
                    </div>
                    {!closed && (
                      <button className="dashBtn dashBtnPrimary" type="button" aria-expanded={open.id === a.id && open.panel === 'status'} onClick={() => toggle(a.id, 'status')}>
                        Update status
                      </button>
                    )}
                    <button className="dashBtn" type="button" aria-expanded={open.id === a.id && open.panel === 'history'} onClick={() => toggle(a.id, 'history')}>
                      History ({a.history.length})
                    </button>
                  </div>
                </div>
                <p className="ui-muted pa-sub pa-row-foot">
                  {a.next_check_in
                    ? `Next check-in: ${a.next_check_in.label}, ${formatDate(a.next_check_in.due_date)}.`
                    : closed ? 'Adoption ended — the animal is back in shelter care.' : 'No check-ins scheduled.'}
                  {a.pending_return ? ' A return request is waiting — see Return requests.' : ''}
                </p>
                {open.id === a.id && open.panel === 'status' && (
                  <UpdateStatus
                    adoption={a}
                    onCancel={() => setOpen({ id: null, panel: '' })}
                    onSaved={() => { setOpen({ id: a.id, panel: 'history' }); list.reload(); onChanged(); }}
                  />
                )}
                {open.id === a.id && open.panel === 'history' && <StatusTimeline history={a.history} />}
              </li>
            );
          })}
        </ul>
      )}
      <Pagination meta={list.meta} onPage={list.setPage} />
    </>
  );
}

// ---------- Adopter updates & Happy Tails ----------

function UpdatesTab({ storiesOnly, onChanged }) {
  const confirm = useConfirm();
  const [filter, setFilter] = useState('all');
  const [error, setError] = useState('');
  const list = usePaged((page) => adminListAdoptionUpdates(storiesOnly ? 'stories' : filter, page), `updates:${storiesOnly ? 'stories' : filter}`);

  const act = async (action, fallback) => {
    setError('');
    try {
      await action();
      list.reload();
      onChanged();
    } catch (err) {
      setError(errorText(err, fallback));
    }
  };

  const review = async (u, decision) => {
    if (decision === 'approved') {
      const ok = await confirm({
        title: `Publish this story about ${u.animal?.name || 'the animal'}?`,
        message: 'It appears in Happy Tails on the public home page, with the adopter’s first name and the photos. The adopter can take it down any time.',
        confirmLabel: 'Publish on Happy Tails',
      });
      if (!ok) return;
    }
    act(() => adminReviewStory(u.id, decision), 'The story could not be updated.');
  };

  return (
    <>
      {!storiesOnly && (
        <div className="pa-chips" role="group" aria-label="Which updates">
          {[['all', 'All updates'], ['unread', 'Unread']].map(([key, label]) => (
            <button key={key} type="button" className={filter === key ? 'active' : ''} aria-pressed={filter === key} onClick={() => { setFilter(key); list.setPage(1); }}>{label}</button>
          ))}
        </div>
      )}
      {storiesOnly && (
        <p className="ui-muted pa-tab-intro">Updates the adopter offered as a public Happy Tails story. Nothing is published until you approve it.</p>
      )}
      {(error || list.error) && <div className="ui-error">{error || list.error}</div>}
      {list.loading ? <div className="ui-empty">Loading…</div> : list.items.length === 0 ? (
        <div className="ui-empty">{storiesOnly ? 'No stories waiting for review.' : 'No updates from adopters yet.'}</div>
      ) : (
        <ul className="pa-list">
          {list.items.map((u) => (
            <li key={u.id} className={`dashCard${u.read ? '' : ' is-unread'}`}>
              <div className="pa-row">
                <PetLine animal={u.animal} adoption={u.adoption} extra={formatDate(u.created_at)} />
                <span className={`pa-feel pa-feel-${u.wellbeing}`}>{labelOf(ADOPTER_WELLBEING, u.wellbeing)}</span>
              </div>
              <p className="pa-message">{u.message}</p>
              {u.photos.length > 0 && (
                <div className="pa-photos">{u.photos.map((src) => <a key={src} href={src} target="_blank" rel="noreferrer"><img src={src} alt="" /></a>)}</div>
              )}
              <div className="pa-row pa-row-foot">
                <span className="ui-muted pa-sub">
                  {u.answered_check_in ? 'Counted as a check-in. ' : ''}
                  {u.share_publicly ? (u.story_status === 'approved' ? 'On Happy Tails.' : u.story_status === 'declined' ? 'Not published.' : 'Offered for Happy Tails.') : ''}
                </span>
                <div className="pa-actions">
                  {u.share_publicly && u.story_status === 'pending' && (
                    <>
                      <button className="dashBtn dashBtnPrimary" type="button" onClick={() => review(u, 'approved')}>Publish on Happy Tails</button>
                      <button className="dashBtn" type="button" onClick={() => review(u, 'declined')}>Don’t publish</button>
                    </>
                  )}
                  {!u.read && <button className="dashBtn" type="button" onClick={() => act(() => adminMarkAdoptionUpdateRead(u.id), 'Could not mark it read.')}>Mark as read</button>}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
      <Pagination meta={list.meta} onPage={list.setPage} />
    </>
  );
}

// ---------- Returns ----------

function ResolveReturn({ request, onDone, onCancel }) {
  const confirm = useConfirm();
  const [destination, setDestination] = useState('available');
  const [notes, setNotes] = useState('');
  const [state, setState] = useState({ saving: false, error: '' });
  const name = request.animal?.name || 'the animal';

  const decide = async (decision) => {
    const ok = await confirm(decision === 'accepted'
      ? {
          title: `Accept the return of ${name}?`,
          message: `The adoption is marked Returned, ${name} goes ${labelOf(RETURN_DESTINATIONS, destination).toLowerCase()}, and the remaining check-ins are cancelled. The adopter is notified.`,
          confirmLabel: 'Accept return',
        }
      : { title: 'Decline this return request?', message: 'The adoption stays as it is. The adopter is notified, with your note.', confirmLabel: 'Decline' });
    if (!ok) return;
    setState({ saving: true, error: '' });
    try {
      await adminResolveReturn(request.id, { decision, animal_status: decision === 'accepted' ? destination : null, staff_notes: notes || null });
      onDone();
    } catch (err) {
      setState({ saving: false, error: errorText(err, 'The request could not be updated.') });
    }
  };

  return (
    <div className="pa-form pa-inline-form">
      {state.error && <div className="ui-error">{state.error}</div>}
      <label>
        <span className="ui-label">If accepted, {name} goes</span>
        <select className="ui-input" value={destination} onChange={(e) => setDestination(e.target.value)}>
          {RETURN_DESTINATIONS.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
        </select>
      </label>
      <label>
        <span className="ui-label">Note to the adopter</span>
        <textarea className="ui-textarea" rows={2} maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Hand-over arrangements, or the support you can offer instead…" />
      </label>
      <div className="pa-actions">
        <button className="dashBtn dashBtnPrimary" type="button" disabled={state.saving} onClick={() => decide('accepted')}>Accept return</button>
        <button className="dashBtn" type="button" disabled={state.saving} onClick={() => decide('declined')}>Decline</button>
        <button className="dashBtn" type="button" disabled={state.saving} onClick={onCancel}>Cancel</button>
      </div>
    </div>
  );
}

function ReturnsTab({ onChanged }) {
  const [deciding, setDeciding] = useState(null);
  const list = usePaged((page) => adminListReturns(page), 'returns');

  return (
    <>
      {list.error && <div className="ui-error">{list.error}</div>}
      {list.loading ? <div className="ui-empty">Loading…</div> : list.items.length === 0 ? (
        <div className="ui-empty">No return requests.</div>
      ) : (
        <ul className="pa-list">
          {list.items.map((r) => (
            <li key={r.id} className="dashCard">
              <div className="pa-row">
                <PetLine animal={r.animal} adoption={r.adoption} extra={`asked ${formatDate(r.created_at)}`} />
                <div className="pa-row-side">
                  <StatusBadge status={r.status} />
                  {r.status === 'pending' && deciding !== r.id && (
                    <button className="dashBtn dashBtnPrimary" type="button" onClick={() => setDeciding(r.id)}>Review</button>
                  )}
                </div>
              </div>
              <p className="pa-message"><strong>Reason:</strong> {r.reason}</p>
              {r.adopter_email && <p className="ui-muted pa-sub">Contact: {r.adopter} · {r.adopter_email}</p>}
              {r.status !== 'pending' && (
                <p className="ui-muted pa-sub">
                  {r.status === 'accepted' ? `Accepted — went ${labelOf(RETURN_DESTINATIONS, r.animal_status).toLowerCase()}` : 'Declined'} on {formatDate(r.resolved_at)}.
                  {r.staff_notes ? ` Note: ${r.staff_notes}` : ''}
                </p>
              )}
              {deciding === r.id && (
                <ResolveReturn request={r} onCancel={() => setDeciding(null)} onDone={() => { setDeciding(null); list.reload(); onChanged(); }} />
              )}
            </li>
          ))}
        </ul>
      )}
      <Pagination meta={list.meta} onPage={list.setPage} />
    </>
  );
}

/**
 * Post-adoption care for staff: check-ins to make, every adopted animal and where it is now
 * (its status and history), updates adopters send (and which to publish as Happy Tails), and
 * return requests to decide. Check-ins are scheduled automatically when an adoption is marked
 * Completed.
 */
export default function PostAdoptionAdmin({ onChanged }) {
  const [tab, setTab] = useState('checkins');
  const [summary, setSummary] = useState(null);
  const [summaryKey, setSummaryKey] = useState(0);

  useEffect(() => {
    let alive = true;
    adminPostAdoptionSummary().then((s) => { if (alive) setSummary(s); }).catch(() => {});
    return () => { alive = false; };
  }, [summaryKey]);

  const changed = () => {
    setSummaryKey((k) => k + 1);
    onChanged?.();
  };

  const tabs = [
    ['checkins', 'Check-ins', summary?.check_ins_due],
    ['adoptions', 'Adopted animals', null],
    ['updates', 'Adopter updates', summary?.unread_updates],
    ['stories', 'Happy Tails', summary?.stories_pending],
    ['returns', 'Return requests', summary?.returns_pending],
  ];

  return (
    <div className="pa-module">
      <h2 className="dashSectionTitle"><HeartHandshake size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Post-adoption</h2>
      <p className="ui-muted pa-tab-intro">
        Completing an adoption schedules check-ins at 1 week, 1 month, 3 months and 6 months. Adopters are asked for an update when each
        comes due, and an update from them counts as the check-in.
      </p>
      <div className="pa-tabs" role="tablist" aria-label="Post-adoption">
        {tabs.map(([key, label, count]) => (
          <button key={key} type="button" role="tab" aria-selected={tab === key} className={tab === key ? 'active' : ''} onClick={() => setTab(key)}>
            {label}{count ? <span className="pa-count">{count}</span> : null}
          </button>
        ))}
      </div>
      <div role="tabpanel">
        {tab === 'checkins' && <CheckInsTab onChanged={changed} />}
        {tab === 'adoptions' && <AdoptionsTab onChanged={changed} />}
        {tab === 'updates' && <UpdatesTab key="updates" onChanged={changed} />}
        {tab === 'stories' && <UpdatesTab key="stories" storiesOnly onChanged={changed} />}
        {tab === 'returns' && <ReturnsTab onChanged={changed} />}
      </div>
    </div>
  );
}
