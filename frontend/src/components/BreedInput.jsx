import { useId, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown } from 'lucide-react';
import { breedGroups } from '../lib/breeds';
import './BreedInput.css';

/** The option text with the part matching the typed query emphasised ("asp" → <mark>Asp</mark>in). */
function Highlight({ text, query }) {
  const at = query ? text.toLowerCase().indexOf(query) : -1;
  if (at < 0) return text;
  return (
    <>
      {text.slice(0, at)}
      <mark>{text.slice(at, at + query.length)}</mark>
      {text.slice(at + query.length)}
    </>
  );
}

/**
 * Breed field with suggestions. Typing narrows a list of common breeds for the chosen species
 * ("asp" → Aspin, Aspin mix), and the ▾ button opens the whole list. It is still a free-text
 * field: a breed that isn't listed is simply typed in, the same as before.
 *
 * Follows the ARIA combobox pattern — ↑/↓ move through the suggestions, Enter picks one, Escape
 * closes the list — so it works from the keyboard and with a screen reader.
 */
export default function BreedInput({ id, value, onChange, species, className = 'ui-input', maxLength = 100, placeholder = 'Start typing, e.g. Aspin' }) {
  const baseId = useId();
  const listId = `${baseId}-list`;
  const optionId = (i) => `${baseId}-opt-${i}`;
  const inputRef = useRef(null);
  const [open, setOpen] = useState(false);
  // What the list is narrowed by: the text being typed, or '' for every breed (when the list is
  // opened with the ▾ button, or when the field is empty or already holds a listed breed).
  const [filter, setFilter] = useState('');
  const [active, setActive] = useState(-1);

  const query = filter.trim().toLowerCase();
  // Matching breeds per group — those starting with the query first, then those containing it —
  // each with the index its first option has in the flat list the arrow keys move through.
  const groups = useMemo(() => {
    const matching = breedGroups(species)
      .map((group) => ({
        ...group,
        breeds: !query ? group.breeds : [
          ...group.breeds.filter((b) => b.toLowerCase().startsWith(query)),
          ...group.breeds.filter((b) => !b.toLowerCase().startsWith(query) && b.toLowerCase().includes(query)),
        ],
      }))
      .filter((group) => group.breeds.length > 0);
    return matching.map((group, g) => ({
      ...group,
      start: matching.slice(0, g).reduce((sum, earlier) => sum + earlier.breeds.length, 0),
    }));
  }, [species, query]);

  const options = groups.flatMap((group) => group.breeds);
  const showList = open && options.length > 0;
  const current = (value || '').trim().toLowerCase();

  const openList = () => {
    const isListed = breedGroups(species).some((g) => g.breeds.some((b) => b.toLowerCase() === current));
    setFilter(!current || isListed ? '' : value);
    setActive(-1);
    setOpen(true);
  };

  const choose = (breed) => {
    onChange(breed);
    setOpen(false);
    setActive(-1);
  };

  const onKeyDown = (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!showList) {
        openList();
        return;
      }
      const step = e.key === 'ArrowDown' ? 1 : -1;
      setActive((i) => (i < 0 && step < 0 ? options.length - 1 : (i + step + options.length) % options.length));
    } else if (e.key === 'Enter' && showList && active >= 0) {
      e.preventDefault(); // pick the suggestion rather than submitting the form
      choose(options[active]);
    } else if (e.key === 'Escape' && open) {
      e.preventDefault();
      setOpen(false);
      setActive(-1);
    }
  };

  return (
    <div className={`breed-combo${showList ? ' is-open' : ''}`}>
      <input
        ref={inputRef}
        id={id}
        className={className}
        value={value}
        maxLength={maxLength}
        placeholder={placeholder}
        autoComplete="off"
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={showList}
        aria-controls={listId}
        aria-activedescendant={showList && active >= 0 ? optionId(active) : undefined}
        onChange={(e) => {
          onChange(e.target.value);
          setFilter(e.target.value);
          setActive(-1);
          setOpen(true);
        }}
        onClick={() => { if (!open) openList(); }}
        onKeyDown={onKeyDown}
        onBlur={() => { setOpen(false); setActive(-1); }}
      />
      <button
        type="button"
        className="breed-combo-toggle"
        tabIndex={-1}
        aria-label={showList ? 'Hide breed suggestions' : 'Show all common breeds'}
        // Keep focus in the input, so the list isn't closed by the input's blur before the click.
        onMouseDown={(e) => e.preventDefault()}
        onClick={() => {
          if (showList) {
            setOpen(false);
          } else {
            setFilter('');
            setActive(-1);
            setOpen(true);
          }
          inputRef.current?.focus();
        }}
      >
        <ChevronDown size={16} aria-hidden="true" />
      </button>

      {showList && (
        <ul id={listId} role="listbox" className="breed-combo-list" aria-label="Common breeds">
          {groups.map((group) => (
            <li key={group.label} role="presentation">
              {groups.length > 1 && <div className="breed-combo-group" aria-hidden="true">{group.label}</div>}
              <ul role="group" aria-label={group.label}>
                {group.breeds.map((breed, j) => {
                  const i = group.start + j;
                  const isCurrent = breed.toLowerCase() === current;
                  return (
                    <li
                      key={breed}
                      id={optionId(i)}
                      role="option"
                      aria-selected={i === active}
                      className={`breed-combo-option${i === active ? ' is-active' : ''}${isCurrent ? ' is-current' : ''}`}
                      ref={i === active ? (el) => el?.scrollIntoView({ block: 'nearest' }) : undefined}
                      onMouseDown={(e) => { e.preventDefault(); choose(breed); }}
                      onMouseEnter={() => setActive(i)}
                    >
                      <span><Highlight text={breed} query={query} /></span>
                      {isCurrent && <Check size={14} className="breed-combo-check" aria-hidden="true" />}
                    </li>
                  );
                })}
              </ul>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
