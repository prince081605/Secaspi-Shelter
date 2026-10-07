import { useEffect, useState } from 'react';
import { HeartHandshake, PawPrint, Send, Undo2 } from 'lucide-react';
import {
  ADOPTER_WELLBEING,
  MAX_UPDATE_PHOTOS,
  getMyAdoptions,
  labelOf,
  requestAdoptionReturn,
  sendAdoptionUpdate,
  stopSharingUpdate,
} from '../../lib/postAdoptionApi';
import PhotoInput from '../../components/PhotoInput';
import StatusBadge from '../../components/StatusBadge';
import useConfirm from '../../lib/useConfirm';
import '../admin/PostAdoption.css';

const formatDate = (value) => (value ? new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '');

const STORY_STATES = {
  pending: 'Offered for Happy Tails — waiting for the shelter to approve it',
  approved: 'Shared on Happy Tails',
  declined: 'Not published on Happy Tails',
};

function UpdateForm({ adoption, onSent }) {
  const name = adoption.animal?.name || 'your pet';
  const [wellbeing, setWellbeing] = useState('doing_well');
  const [message, setMessage] = useState('');
  const [photos, setPhotos] = useState([]);
  const [share, setShare] = useState(false);
  const [state, setState] = useState({ sending: false, error: '' });

  const choosePhotos = (files) => {
    setPhotos(files.slice(0, MAX_UPDATE_PHOTOS));
    setState((s) => ({ ...s, error: files.length > MAX_UPDATE_PHOTOS ? `Only the first ${MAX_UPDATE_PHOTOS} photos were kept.` : '' }));
  };

  const submit = async (e) => {
    e.preventDefault();
    setState({ sending: true, error: '' });
    try {
      await sendAdoptionUpdate(adoption.id, { wellbeing, message, photos, sharePublicly: share });
      setMessage('');
      setPhotos([]);
      setShare(false);
      setState({ sending: false, error: '' });
      onSent();
    } catch (err) {
      setState({ sending: false, error: err?.message || 'The update could not be sent. Please try again.' });
    }
  };

  return (
    <form className="pa-form" onSubmit={submit}>
      {state.error && <div className="ui-error">{state.error}</div>}
      <fieldset className="pa-choice">
        <legend className="ui-label ui-label-required">How is {name} doing?</legend>
        {ADOPTER_WELLBEING.map((o) => (
          <label key={o.value} className={wellbeing === o.value ? 'is-on' : ''}>
            <input type="radio" name={`wellbeing-${adoption.id}`} value={o.value} checked={wellbeing === o.value} onChange={() => setWellbeing(o.value)} />
            {o.label}
          </label>
        ))}
      </fieldset>
      <div className="ui-field">
        <label className="ui-label ui-label-required" htmlFor={`update-${adoption.id}`}>Tell us about it</label>
        <textarea
          id={`update-${adoption.id}`}
          className="ui-textarea"
          rows={4}
          maxLength={2000}
          required
          placeholder={`Favourite spot, new tricks, how ${name} gets along with everyone…`}
          value={message}
          onChange={(e) => setMessage(e.target.value)}
        />
      </div>
      <div className="ui-field">
        <label className="ui-label">Photos (up to {MAX_UPDATE_PHOTOS})</label>
        <PhotoInput multiple files={photos} onChange={choosePhotos} disabled={state.sending} cameraTitle={`Photograph ${name}`} />
      </div>
      <label className="pa-check">
        <input type="checkbox" checked={share} onChange={(e) => setShare(e.target.checked)} />
        <span>
          Share this as a <strong>Happy Tails</strong> story on the SECASPI website. The shelter checks it first, and only your
          first name is shown. You can stop sharing it any time.
        </span>
      </label>
      <button className="ui-btn-primary" type="submit" disabled={state.sending}>
        <Send size={16} aria-hidden="true" style={{ verticalAlign: '-3px' }} /> {state.sending ? 'Sending…' : 'Send update'}
      </button>
    </form>
  );
}

function ReturnRequest({ adoption, onSent }) {
  const confirm = useConfirm();
  const name = adoption.animal?.name || 'your pet';
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [state, setState] = useState({ sending: false, error: '' });

  const submit = async (e) => {
    e.preventDefault();
    const ok = await confirm({
      title: `Ask to return ${name}?`,
      message: 'The shelter will contact you before anything happens. Nothing changes until they review your request.',
      confirmLabel: 'Send request',
    });
    if (!ok) return;
    setState({ sending: true, error: '' });
    try {
      await requestAdoptionReturn(adoption.id, reason);
      setOpen(false);
      onSent();
    } catch (err) {
      setState({ sending: false, error: err?.message || 'The request could not be sent. Please try again.' });
    }
  };

  if (!open) {
    return (
      <button type="button" className="pa-link" onClick={() => setOpen(true)}>
        <Undo2 size={14} aria-hidden="true" /> Need to return {name}?
      </button>
    );
  }

  return (
    <form className="pa-form pa-return-form" onSubmit={submit}>
      <p className="ui-muted">
        We&apos;re sorry things aren&apos;t working out. Tell us what&apos;s happening — the shelter will reach out, and may be able to help
        with training, health or other support before a return.
      </p>
      {state.error && <div className="ui-error">{state.error}</div>}
      <label className="ui-label ui-label-required" htmlFor={`return-${adoption.id}`}>Why do you need to return {name}?</label>
      <textarea id={`return-${adoption.id}`} className="ui-textarea" rows={3} maxLength={2000} required value={reason} onChange={(e) => setReason(e.target.value)} />
      <div className="pa-actions">
        <button className="dashBtn dashBtnDanger" type="submit" disabled={state.sending}>{state.sending ? 'Sending…' : 'Send return request'}</button>
        <button className="dashBtn" type="button" onClick={() => setOpen(false)} disabled={state.sending}>Cancel</button>
      </div>
    </form>
  );
}

function AdoptionCard({ adoption, onChanged }) {
  const confirm = useConfirm();
  const [composing, setComposing] = useState(false);
  const [notice, setNotice] = useState('');
  const name = adoption.animal?.name || 'Your pet';
  const isActive = adoption.status === 'completed';
  const ret = adoption.return_request;

  const stopSharing = async (update) => {
    const ok = await confirm({
      title: 'Stop sharing this story?',
      message: 'It comes off the Happy Tails page straight away. The update itself stays with the shelter.',
      confirmLabel: 'Stop sharing',
    });
    if (!ok) return;
    await stopSharingUpdate(adoption.id, update.id).catch(() => {});
    onChanged();
  };

  return (
    <article className="dashCard pa-adoption">
      <header className="pa-adoption-head">
        <div className="pa-pet-photo">{adoption.animal?.photo ? <img src={adoption.animal.photo} alt="" /> : <PawPrint size={26} aria-hidden="true" />}</div>
        <div>
          <h3>{name}</h3>
          <p className="ui-muted">
            {[adoption.animal?.species, adoption.animal?.breed].filter(Boolean).join(' • ')}
            {adoption.completed_at ? ` · Adopted ${formatDate(adoption.completed_at)}` : ''}
          </p>
        </div>
        {!isActive && <StatusBadge status={adoption.status} />}
      </header>

      {isActive && adoption.next_check_in && (
        <p className="pa-next">
          Our next check-in is <strong>{adoption.next_check_in.label}</strong> after the adoption, around {formatDate(adoption.next_check_in.due_date)}.
          Sending an update around then counts as your check-in.
        </p>
      )}

      {ret?.status === 'pending' && <div className="ui-notice">Your request to return {name} is waiting for the shelter to review. They will contact you.</div>}
      {ret?.status === 'accepted' && (
        <div className="ui-notice">The shelter accepted your request to return {name}.{ret.staff_notes ? ` Note: ${ret.staff_notes}` : ''}</div>
      )}
      {ret?.status === 'declined' && isActive && (
        <div className="ui-notice">The shelter reviewed your return request.{ret.staff_notes ? ` Note: ${ret.staff_notes}` : ''} Message us if you need help.</div>
      )}

      {notice && <div className="ui-success-msg" role="status">{notice}</div>}

      {isActive && (composing ? (
        <UpdateForm adoption={adoption} onSent={() => { setComposing(false); setNotice(`Thank you! Your update about ${name} was sent to the shelter.`); onChanged(); }} />
      ) : (
        <button type="button" className="ui-btn-primary pa-compose" onClick={() => { setComposing(true); setNotice(''); }}>
          <Send size={16} aria-hidden="true" style={{ verticalAlign: '-3px' }} /> Send an update about {name}
        </button>
      ))}

      {adoption.updates.length > 0 && (
        <section className="pa-history" aria-label={`Your updates about ${name}`}>
          <h4>Your updates</h4>
          <ul>
            {adoption.updates.map((u) => (
              <li key={u.id}>
                <div className="pa-history-meta">
                  <span>{formatDate(u.created_at)}</span>
                  <span className={`pa-feel pa-feel-${u.wellbeing}`}>{labelOf(ADOPTER_WELLBEING, u.wellbeing)}</span>
                </div>
                <p>{u.message}</p>
                {u.photos.length > 0 && (
                  <div className="pa-photos">{u.photos.map((src) => <a key={src} href={src} target="_blank" rel="noreferrer"><img src={src} alt="" /></a>)}</div>
                )}
                {u.share_publicly && u.story_status && (
                  <div className="pa-story-line">
                    <span>{STORY_STATES[u.story_status]}</span>
                    <button type="button" className="pa-link" onClick={() => stopSharing(u)}>Stop sharing</button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}

      {isActive && ret?.status !== 'pending' && <ReturnRequest adoption={adoption} onSent={onChanged} />}
    </article>
  );
}

/**
 * "My Adopted Pets": every adoption of the signed-in member that has been completed — send the
 * shelter updates (which answer their check-ins), share Happy Tails stories, or ask to return.
 */
export default function MyAdoptionsPanel() {
  const [adoptions, setAdoptions] = useState(null);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let alive = true;
    getMyAdoptions()
      .then((data) => { if (alive) setAdoptions(data?.adoptions || []); })
      .catch((err) => { if (alive) setError(err?.message || 'Could not load your adopted pets.'); });
    return () => { alive = false; };
  }, [refreshKey]);

  const reload = () => setRefreshKey((k) => k + 1);

  return (
    <div className="pa-module">
      <h2 className="dashSectionTitle"><HeartHandshake size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />My Adopted Pets</h2>
      {error && <div className="ui-error">{error}</div>}
      {!adoptions && !error ? (
        <div className="ui-empty">Loading…</div>
      ) : adoptions?.length === 0 ? (
        <div className="ui-empty">
          When one of your adoptions is completed, your new family member appears here. You can send the shelter updates and
          photos, and we will check in to see how they are settling in.
        </div>
      ) : (
        <div className="pa-adoptions">
          {adoptions?.map((a) => <AdoptionCard key={a.id} adoption={a} onChanged={reload} />)}
        </div>
      )}
    </div>
  );
}
