import { useEffect, useState } from 'react';
import { Brain, Cat, Check, Dog, Eye, EyeOff, IdCard, MapPin, Pencil, Plus, Search, Stethoscope, Tags, Trash2, X } from 'lucide-react';
import {
  adminAddCategoryOption,
  adminDeleteCategoryOption,
  adminGetCategory,
  adminRenameCategoryOption,
  adminSetCategoryOptionHidden,
} from '../../lib/categoriesApi';
import useConfirm from '../../lib/useConfirm';
import ShelterLocationsManager from './ShelterLocationsManager';
import './CategoriesAdmin.css';

// The lists an admin manages here. `renames` says what a rename does to existing records, in the
// words shown before confirming it.
const LISTS = [
  {
    type: 'dog_breed', label: 'Dog breeds', icon: Dog,
    about: 'Suggested in the Breed field when adding or editing a dog. Staff can still type a breed that isn’t listed.',
    renames: (n) => `It is also renamed on the ${plural(n, 'dog')} that ${n === 1 ? 'has' : 'have'} this breed.`,
  },
  {
    type: 'cat_breed', label: 'Cat breeds', icon: Cat,
    about: 'Suggested in the Breed field when adding or editing a cat. Staff can still type a breed that isn’t listed.',
    renames: (n) => `It is also renamed on the ${plural(n, 'cat')} that ${n === 1 ? 'has' : 'have'} this breed.`,
  },
  {
    type: 'behavioral_issue', label: 'Behavioral issues', icon: Brain,
    about: 'The checkboxes on the animal form, and the issues the Excel import accepts. The Matchmaker and care guides look for these words, so keep the wording clear.',
    renames: (n) => `It is also renamed on the ${plural(n, 'animal')} that ${n === 1 ? 'has' : 'have'} this issue.`,
  },
  {
    type: 'medical_record_type', label: 'Medical record types', icon: Stethoscope,
    about: 'The types offered when adding a medical record, and the Record type filter in Reports.',
    renames: (n) => `The ${plural(n, 'medical record')} of this type will show the new name.`,
  },
  {
    type: 'valid_id_type', label: 'Valid ID types', icon: IdCard,
    about: 'The IDs adoption and volunteer applicants can choose from.',
    renames: (n) => `The ${plural(n, 'application')} that used this ID will show the new name.`,
  },
];

function plural(n, word) {
  return `${n} ${word}${n === 1 ? '' : 's'}`;
}

/**
 * One list: add, rename, hide/restore, and delete (only while nothing uses it). The server does
 * the real checks — duplicates, the last visible option, options in use — and its messages are
 * shown as they come.
 */
function CategoryList({ list }) {
  const confirm = useConfirm();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [newLabel, setNewLabel] = useState('');
  const [editing, setEditing] = useState(null); // { id, label }
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let alive = true;
    adminGetCategory(list.type)
      .then((res) => { if (alive) setData(res); })
      .catch((err) => { if (alive) setError(err?.message || 'Could not load this list.'); });
    return () => { alive = false; };
  }, [list.type, refreshKey]);

  const run = async (action, success) => {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const result = await action();
      setNotice(typeof success === 'function' ? success(result) : success);
      setRefreshKey((k) => k + 1);
      return true;
    } catch (err) {
      setError(Object.values(err?.data?.errors || {})[0]?.[0] || err?.message || 'That didn’t work. Please try again.');
      return false;
    } finally {
      setBusy(false);
    }
  };

  const usedBy = (n) => (n ? `Used by ${plural(n, data?.used_by || 'record')}` : 'Not used yet');

  const add = async (e) => {
    e.preventDefault();
    const label = newLabel.trim();
    if (!label) return;
    if (await run(() => adminAddCategoryOption(list.type, label), `Added “${label}”.`)) setNewLabel('');
  };

  const saveRename = async (e, option) => {
    e.preventDefault();
    const label = editing.label.trim();
    if (!label || label === option.label) {
      setEditing(null);
      return;
    }
    if (option.usage > 0) {
      const ok = await confirm({
        title: `Rename “${option.label}” to “${label}”?`,
        message: list.renames(option.usage),
        confirmLabel: 'Rename',
      });
      if (!ok) return;
    }
    const done = await run(
      () => adminRenameCategoryOption(list.type, option.id, label),
      (res) => (res?.records_updated ? `Renamed — ${plural(res.records_updated, data?.used_by || 'record')} updated.` : `Renamed to “${label}”.`),
    );
    if (done) setEditing(null);
  };

  const toggleHidden = async (option) => {
    if (!option.hidden) {
      const ok = await confirm({
        title: `Hide “${option.label}”?`,
        message: option.usage
          ? `It won’t be offered for new entries. The ${plural(option.usage, data?.used_by || 'record')} that already ${option.usage === 1 ? 'has' : 'have'} it keep it. You can show it again any time.`
          : 'It won’t be offered for new entries. You can show it again any time.',
        confirmLabel: 'Hide',
      });
      if (!ok) return;
    }
    run(
      () => adminSetCategoryOptionHidden(list.type, option.id, !option.hidden),
      option.hidden ? `“${option.label}” is shown again.` : `“${option.label}” is hidden.`,
    );
  };

  const remove = async (option) => {
    const ok = await confirm({
      title: `Delete “${option.label}”?`,
      message: 'Nothing uses it yet, so it is removed from the list for good.',
      confirmLabel: 'Delete',
      tone: 'danger',
    });
    if (!ok) return;
    run(() => adminDeleteCategoryOption(list.type, option.id), `Deleted “${option.label}”.`);
  };

  const options = data?.options || [];
  const needle = query.trim().toLowerCase();
  const shown = needle ? options.filter((o) => o.label.toLowerCase().includes(needle)) : options;
  const hiddenCount = options.filter((o) => o.hidden).length;

  return (
    <div className="dashCard cat-panel">
      <p className="ui-muted cat-about">{list.about}</p>

      <form onSubmit={add} className="cat-add">
        <input
          className="ui-input"
          maxLength={100}
          placeholder={`Add to ${list.label.toLowerCase()}`}
          aria-label={`New entry for ${list.label}`}
          value={newLabel}
          onChange={(e) => setNewLabel(e.target.value)}
        />
        <button className="dashBtn dashBtnPrimary" type="submit" disabled={busy || !newLabel.trim()}>
          <Plus size={15} aria-hidden="true" /> Add
        </button>
      </form>

      {error && <div className="ui-error" role="alert">{error}</div>}
      {notice && <div className="ui-success-msg" role="status">{notice}</div>}

      <div className="cat-toolbar">
        <span className="ui-muted">
          {options.length} {options.length === 1 ? 'entry' : 'entries'}{hiddenCount ? ` · ${hiddenCount} hidden` : ''}
        </span>
        {options.length > 10 && (
          <label className="cat-search">
            <Search size={15} aria-hidden="true" />
            <input type="search" placeholder="Search this list" aria-label={`Search ${list.label}`} value={query} onChange={(e) => setQuery(e.target.value)} />
          </label>
        )}
      </div>

      {!data && !error ? (
        <div className="ui-empty">Loading…</div>
      ) : shown.length === 0 ? (
        <div className="ui-empty">{needle ? 'Nothing matches that search.' : 'This list is empty.'}</div>
      ) : (
        <ul className="cat-list">
          {shown.map((option) => (
            <li key={option.id} className={option.hidden ? 'is-hidden' : ''}>
              {editing?.id === option.id ? (
                <form className="cat-rename" onSubmit={(e) => saveRename(e, option)}>
                  <input
                    className="ui-input"
                    maxLength={100}
                    aria-label={`New name for ${option.label}`}
                    value={editing.label}
                    onChange={(e) => setEditing({ ...editing, label: e.target.value })}
                    onKeyDown={(e) => { if (e.key === 'Escape') setEditing(null); }}
                    autoFocus
                  />
                  <button className="dashBtn dashBtnPrimary" type="submit" disabled={busy} aria-label="Save the new name"><Check size={15} aria-hidden="true" /> Save</button>
                  <button className="dashBtn" type="button" onClick={() => setEditing(null)} aria-label="Cancel renaming"><X size={15} aria-hidden="true" /></button>
                </form>
              ) : (
                <>
                  <div className="cat-name">
                    <span className="label">{option.label}</span>
                    {option.hidden && <span className="badge badgeOrange">Hidden</span>}
                    <span className="usage">{usedBy(option.usage)}</span>
                  </div>
                  <div className="cat-actions">
                    <button className="dashBtn" type="button" disabled={busy} onClick={() => setEditing({ id: option.id, label: option.label })} aria-label={`Rename ${option.label}`}>
                      <Pencil size={14} aria-hidden="true" /> Rename
                    </button>
                    <button className="dashBtn" type="button" disabled={busy} onClick={() => toggleHidden(option)} aria-label={`${option.hidden ? 'Show' : 'Hide'} ${option.label}`}>
                      {option.hidden ? <Eye size={14} aria-hidden="true" /> : <EyeOff size={14} aria-hidden="true" />} {option.hidden ? 'Show' : 'Hide'}
                    </button>
                    {option.usage === 0 && (
                      <button className="dashBtn dashBtnDanger" type="button" disabled={busy} onClick={() => remove(option)} aria-label={`Delete ${option.label}`}>
                        <Trash2 size={14} aria-hidden="true" />
                      </button>
                    )}
                  </div>
                </>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Categories: one place for the lists the rest of the system offers as choices — breeds,
 * behavioral issues, medical record types, valid ID types, and the shelter's areas. Admin-only.
 */
export default function CategoriesAdmin() {
  const [active, setActive] = useState(LISTS[0].type);
  const list = LISTS.find((l) => l.type === active);

  return (
    <div className="cat-module">
      <h2 className="dashSectionTitle"><Tags size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Categories</h2>
      <p className="ui-muted cat-intro">
        The lists the system offers as choices. Hiding an entry takes it off the forms without changing any record that already uses it.
        An entry can only be deleted while nothing uses it.
      </p>

      <div className="cat-tabs" role="tablist" aria-label="Lists">
        {[...LISTS, { type: 'areas', label: 'Shelter areas', icon: MapPin }].map(({ type, label, icon: Icon }) => (
          <button
            key={type}
            type="button"
            role="tab"
            aria-selected={active === type}
            className={active === type ? 'active' : ''}
            onClick={() => setActive(type)}
          >
            <Icon size={15} aria-hidden="true" /> {label}
          </button>
        ))}
      </div>

      <div role="tabpanel" aria-label={list?.label || 'Shelter areas'}>
        {list ? <CategoryList key={list.type} list={list} /> : <ShelterLocationsManager />}
      </div>
    </div>
  );
}
