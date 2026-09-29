import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { createShelterLocation, deleteShelterLocation, listShelterLocations, renameShelterLocation } from '../../lib/locationsApi';
import useConfirm from '../../lib/useConfirm';

/**
 * Admin-only list of the shelter's areas (House 1, Kennel 2, ...): add, rename, remove. An area
 * with animals in it can't be removed (the server refuses and says so).
 */
export default function ShelterLocationsManager({ onChanged }) {
  const confirm = useConfirm();
  const [locations, setLocations] = useState([]);
  const [newName, setNewName] = useState('');
  const [editing, setEditing] = useState(null); // { id, name }
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let mounted = true;
    listShelterLocations()
      .then((res) => { if (mounted) setLocations(res?.locations || []); })
      .catch((err) => { if (mounted) setError(err?.message || 'Could not load locations.'); });
    return () => { mounted = false; };
  }, [refreshKey]);

  const changed = () => {
    setRefreshKey((k) => k + 1);
    onChanged?.();
  };

  const fail = (err, fallback) => setError(Object.values(err?.data?.errors || {})[0]?.[0] || err?.message || fallback);

  const add = async (e) => {
    e.preventDefault();
    setError('');
    try {
      await createShelterLocation(newName.trim());
      setNewName('');
      changed();
    } catch (err) {
      fail(err, 'Could not add the area.');
    }
  };

  const saveRename = async (e) => {
    e.preventDefault();
    setError('');
    try {
      await renameShelterLocation(editing.id, editing.name.trim());
      setEditing(null);
      changed();
    } catch (err) {
      fail(err, 'Could not rename the area.');
    }
  };

  const remove = async (loc) => {
    const ok = await confirm({
      title: `Remove ${loc.name}?`,
      message: 'Past moves keep their history. An area that still has animals in it cannot be removed.',
      confirmLabel: 'Remove area',
      tone: 'danger',
    });
    if (!ok) return;
    setError('');
    try {
      await deleteShelterLocation(loc.id);
      changed();
    } catch (err) {
      fail(err, 'Could not remove the area.');
    }
  };

  return (
    <div className="dashCard" style={{ marginTop: 10 }}>
      <div className="dashReviewSectionTitle">Shelter areas</div>
      {error && <div className="ui-error">{error}</div>}
      <ul style={{ listStyle: 'none', padding: 0, margin: '0 0 12px' }}>
        {locations.map((loc) => (
          <li key={loc.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0', borderBottom: '1px solid var(--line)', flexWrap: 'wrap' }}>
            {editing?.id === loc.id ? (
              <form onSubmit={saveRename} style={{ display: 'flex', gap: 6, flex: 1, flexWrap: 'wrap' }}>
                <input className="ui-input" style={{ maxWidth: 220 }} maxLength={100} value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} required autoFocus />
                <button className="dashBtn dashBtnPrimary" type="submit">Save</button>
                <button className="dashBtn" type="button" onClick={() => setEditing(null)}>Cancel</button>
              </form>
            ) : (
              <>
                <span style={{ flex: 1, fontWeight: 600 }}>{loc.name}</span>
                <span className="ui-muted" style={{ fontSize: 13 }}>{loc.animal_count} animal{loc.animal_count === 1 ? '' : 's'}</span>
                <button className="dashBtn" onClick={() => setEditing({ id: loc.id, name: loc.name })}>Rename</button>
                <button className="dashBtn dashBtnDanger" aria-label={`Remove ${loc.name}`} onClick={() => remove(loc)}><X size={14} /></button>
              </>
            )}
          </li>
        ))}
      </ul>
      <form onSubmit={add} style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        <input className="ui-input" style={{ maxWidth: 220 }} maxLength={100} placeholder="New area, e.g. Kennel 3" value={newName} onChange={(e) => setNewName(e.target.value)} required />
        <button className="dashBtn dashBtnPrimary" type="submit" disabled={!newName.trim()}>+ Add area</button>
      </form>
    </div>
  );
}
