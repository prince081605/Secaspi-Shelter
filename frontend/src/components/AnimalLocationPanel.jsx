import { useEffect, useState } from 'react';
import { MapPin } from 'lucide-react';
import { adminGetAnimal } from '../lib/animalsApi';
import { listShelterLocations, moveAnimal } from '../lib/locationsApi';

const styles = `
  .alpCurrent { display: flex; align-items: center; gap: 0.5rem; font-size: 1.05rem; font-weight: 700; }
  .alpForm { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin-top: 0.8rem; }
  .alpForm .ui-field { margin-bottom: 0; }
  .alpHistory { list-style: none; padding: 0; margin: 0.9rem 0 0; font-size: 0.85rem; }
  .alpHistory li { padding: 0.45rem 0; border-bottom: 1px solid var(--line); }
  .alpHistory li:last-child { border-bottom: none; }
  .alpMeta { color: var(--muted); font-size: 0.78rem; margin-top: 0.1rem; }
`;

const nameOf = (loc) => loc?.name || 'Unassigned';

function formatWhen(value) {
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * Where an animal is in the shelter, a control to move it, and its recent moves. Staff-only
 * (the endpoints it uses are staff-gated). `source` is logged with each move: 'qr_page' when
 * used on the animal's page — the page its QR code opens — and 'admin' in the Animals panel.
 */
export default function AnimalLocationPanel({ animalId, source = 'admin', onMoved }) {
  const [animal, setAnimal] = useState(null);
  const [locations, setLocations] = useState([]);
  const [target, setTarget] = useState('');
  const [note, setNote] = useState('');
  const [state, setState] = useState({ status: 'loading', error: '', saved: '' });

  useEffect(() => {
    let mounted = true;
    Promise.all([adminGetAnimal(animalId), listShelterLocations()])
      .then(([a, l]) => {
        if (!mounted) return;
        setAnimal(a?.animal || null);
        setLocations(l?.locations || []);
        setState({ status: 'idle', error: '', saved: '' });
      })
      .catch((err) => { if (mounted) setState({ status: 'error', error: err?.message || 'Could not load the location.', saved: '' }); });
    return () => { mounted = false; };
  }, [animalId]);

  const submit = async (e) => {
    e.preventDefault();
    if (target === '') return;
    setState({ status: 'saving', error: '', saved: '' });
    try {
      const res = await moveAnimal(animalId, { locationId: target === 'none' ? null : Number(target), note, source });
      setAnimal(res?.animal || animal);
      setTarget('');
      setNote('');
      setState({ status: 'idle', error: '', saved: `Moved to ${nameOf(res?.animal?.current_location)}.` });
      onMoved?.();
    } catch (err) {
      const fieldError = Object.values(err?.data?.errors || {})[0]?.[0];
      setState({ status: 'idle', error: fieldError || err?.message || 'Could not update the location.', saved: '' });
    }
  };

  if (state.status === 'loading') return <div className="ui-empty">Loading location…</div>;
  if (!animal) return <div className="ui-error">{state.error || 'Could not load the location.'}</div>;

  const current = animal.current_location;
  const history = animal.location_history || [];

  return (
    <div>
      <style>{styles}</style>
      {state.error && <div className="ui-error">{state.error}</div>}
      {state.saved && <div className="ui-notice">{state.saved}</div>}

      <div className="alpCurrent">
        <MapPin size={18} aria-hidden="true" />
        <span>{current ? current.name : 'No location recorded yet'}</span>
      </div>

      <form className="alpForm" onSubmit={submit}>
        <div className="ui-field" style={{ flex: '1 1 160px' }}>
          <label className="ui-label ui-label-required">Move to</label>
          <select className="ui-input" value={target} onChange={(e) => setTarget(e.target.value)} required>
            <option value="">Select an area</option>
            {locations.filter((l) => l.id !== current?.id).map((l) => (
              <option key={l.id} value={l.id}>{l.name}</option>
            ))}
            {current && <option value="none">Unassigned (left the shelter)</option>}
          </select>
        </div>
        <div className="ui-field" style={{ flex: '2 1 200px' }}>
          <label className="ui-label">Note (optional)</label>
          <input className="ui-input" maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Moved for cleaning" />
        </div>
        <button className="ui-btn-primary" type="submit" disabled={state.status === 'saving' || target === ''}>
          {state.status === 'saving' ? 'Saving…' : 'Update location'}
        </button>
      </form>

      {history.length > 0 && (
        <>
          <div className="ui-label" style={{ marginTop: '1rem' }}>Recent moves</div>
          <ul className="alpHistory">
            {history.map((h) => (
              <li key={h.id}>
                <div>{nameOf(h.from)} → <strong>{nameOf(h.to)}</strong>{h.note ? ` — ${h.note}` : ''}</div>
                <div className="alpMeta">
                  {formatWhen(h.created_at)} · by {h.moved_by || 'unknown'} · {h.source === 'qr_page' ? 'via QR scan page' : 'via admin panel'}
                </div>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}
