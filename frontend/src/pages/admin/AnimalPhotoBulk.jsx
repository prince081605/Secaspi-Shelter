import { useEffect, useMemo, useRef, useState } from 'react';
import { Images, LoaderCircle, Upload, X } from 'lucide-react';
import { adminAddAnimalPhotos, adminMatchAnimalPhotos } from '../../lib/animalsApi';
import useConfirm from '../../lib/useConfirm';

const MAX_FILES = 300;
const MAX_BYTES = 5 * 1024 * 1024; // the same 5 MB limit as every other photo upload

const HOW = {
  id: ['Matched by ID', 'badgeGreen'],
  name: ['Matched by name', 'badgeGreen'],
  ambiguous: ['Pick the animal', 'badgeOrange'],
  none: ['No match', 'badgeOrange'],
  chosen: ['Chosen by you', 'badgeSky'],
  invalid: ['Can’t be used', 'badgeOrange'],
};

const plural = (n, word) => `${n.toLocaleString()} ${word}${n === 1 ? '' : 's'}`;

function Step({ number, title, children }) {
  return (
    <section className="aa-import-step">
      <div className="aa-import-step-num" aria-hidden="true">{number}</div>
      <div>
        <h3>{title}</h3>
        {children}
      </div>
    </section>
  );
}

function Spinner() {
  return <LoaderCircle size={16} className="aa-import-spin" aria-hidden="true" />;
}

const animalLabel = (a) => `${a.name} — ${a.species || 'animal'}, #${a.id}${a.status === 'archived' ? ' (archived)' : ''}`;

/**
 * Admin-only bulk photo update: pick many photos at once, each named after its animal
 * ("Brownie.jpg", "Brownie-2.jpg") or its ID ("42.jpg"). The server matches the names first; the
 * admin checks the matches, fixes any it couldn't make, and only then are the photos sent — one
 * at a time, through the same upload as Manage → Photos.
 */
export default function AnimalPhotoBulk({ onUploaded, onClose }) {
  const confirm = useConfirm();
  const inputRef = useRef(null);
  const [rows, setRows] = useState([]); // { key, file, url, how, animalId, candidates, problem }
  const [animals, setAnimals] = useState([]);
  const [makeMain, setMakeMain] = useState(false);
  const [phase, setPhase] = useState('idle'); // idle | matching | review | uploading | done
  const [progress, setProgress] = useState({ done: 0, total: 0 });
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  // Preview images are released when the photos are replaced and when the panel closes — not on
  // every change to the matches, which reuse the same images.
  const urlsRef = useRef([]);
  const releaseUrls = () => {
    urlsRef.current.forEach((url) => URL.revokeObjectURL(url));
    urlsRef.current = [];
  };
  useEffect(() => releaseUrls, []);

  const animalsById = useMemo(() => new Map(animals.map((a) => [a.id, a])), [animals]);
  const ready = rows.filter((r) => !r.problem && r.animalId);
  const animalCount = new Set(ready.map((r) => r.animalId)).size;
  const counts = {
    matched: rows.filter((r) => !r.problem && r.animalId).length,
    needChoice: rows.filter((r) => !r.problem && !r.animalId).length,
    unusable: rows.filter((r) => r.problem).length,
  };

  const choose = async (event) => {
    const files = [...(event.target.files || [])];
    event.target.value = '';
    if (!files.length) return;
    if (files.length > MAX_FILES) {
      setError(`Choose up to ${MAX_FILES} photos at a time.`);
      return;
    }

    setError('');
    setResult(null);
    setPhase('matching');
    releaseUrls();
    const picked = files.map((file, i) => ({
      key: `${i}-${file.name}`,
      file,
      url: URL.createObjectURL(file),
      problem: !file.type.startsWith('image/') ? 'Not a photo' : file.size > MAX_BYTES ? 'Larger than 5 MB' : '',
    }));
    urlsRef.current = picked.map((p) => p.url);

    try {
      const res = await adminMatchAnimalPhotos(picked.map((p) => p.file.name));
      setAnimals(res?.animals || []);
      setRows(picked.map((p, i) => {
        const m = res?.matches?.[i] || {};
        return {
          ...p,
          how: p.problem ? 'invalid' : m.how || 'none',
          animalId: p.problem ? null : m.animal?.id || null,
          candidates: m.candidates || [],
        };
      }));
      setPhase('review');
    } catch (err) {
      releaseUrls();
      setRows([]);
      setError(err?.message || 'Could not match the photos. Please try again.');
      setPhase('idle');
    }
  };

  const setAnimalFor = (key, value) => {
    setRows((current) => current.map((r) => (r.key === key ? { ...r, animalId: value ? Number(value) : null, how: value ? 'chosen' : r.how === 'chosen' ? 'none' : r.how } : r)));
  };

  const upload = async () => {
    const ok = await confirm({
      title: `Add ${plural(ready.length, 'photo')}?`,
      message: makeMain
        ? 'Each animal’s first new photo becomes the one shown on its profile. Existing photos are kept.'
        : 'They are added to each animal’s photos. Existing photos are kept; an animal with no photo gets its first one as the main photo.',
      confirmLabel: `Add ${plural(ready.length, 'photo')}`,
      summary: [
        { label: 'Photos', value: String(ready.length) },
        { label: 'Animals', value: String(animalCount) },
        { label: 'Skipped', value: rows.length - ready.length ? String(rows.length - ready.length) : '' },
      ],
    });
    if (!ok) return;

    setPhase('uploading');
    setProgress({ done: 0, total: ready.length });
    const failed = [];
    const firstFor = new Set();
    let added = 0;
    for (const row of ready) {
      const fd = new FormData();
      fd.append('photos[]', row.file);
      if (makeMain && !firstFor.has(row.animalId)) fd.append('make_main', '1');
      firstFor.add(row.animalId);
      try {
        await adminAddAnimalPhotos(row.animalId, fd);
        added += 1;
      } catch (err) {
        failed.push({ file: row.file.name, reason: err?.message || 'Upload failed.' });
      }
      setProgress((p) => ({ ...p, done: p.done + 1 }));
    }

    setResult({
      added,
      animals: new Set(ready.filter((r) => !failed.some((f) => f.file === r.file.name)).map((r) => r.animalId)).size,
      skipped: rows.length - ready.length,
      failed,
    });
    setPhase('done');
    onUploaded?.();
  };

  const startOver = () => {
    releaseUrls();
    setRows([]);
    setResult(null);
    setError('');
    setPhase('idle');
  };

  const busy = phase === 'matching' || phase === 'uploading';

  return (
    <div className="dashCard aa-import">
      <div className="aa-import-head">
        <div>
          <h3><Images size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Update photos in bulk</h3>
          <p className="ui-muted">Add photos to many animals at once. Every photo is matched to an animal before anything is uploaded.</p>
        </div>
        <button type="button" className="dashBtn" onClick={onClose} disabled={phase === 'uploading'} aria-label="Close bulk photo update">
          <X size={14} />
        </button>
      </div>

      <Step number={1} title="Name your photos">
        <p className="ui-muted">
          Name each photo after the animal, like <strong>Brownie.jpg</strong> (more photos: <strong>Brownie-2.jpg</strong>), or start it with
          the animal’s ID number, like <strong>42.jpg</strong>. Use the ID when two animals share a name.
        </p>
      </Step>

      {phase === 'done' && result ? (
        <section className="aa-import-step aa-import-result" aria-live="polite">
          <div className="aa-import-step-num" aria-hidden="true">✓</div>
          <div>
            <h3>Photos added</h3>
            <div className="ui-success-msg">{plural(result.added, 'photo')} added to {plural(result.animals, 'animal')}.</div>
            {result.skipped > 0 && <p className="ui-muted">{plural(result.skipped, 'photo')} skipped (no animal chosen, or not usable).</p>}
            {result.failed.length > 0 && (
              <div className="aa-import-block">
                <h4>Not uploaded</h4>
                <ul className="aa-import-list">
                  {result.failed.map((f) => <li key={f.file}><strong>{f.file}:</strong> {f.reason}</li>)}
                </ul>
              </div>
            )}
            <div className="aa-import-actions">
              <button type="button" className="dashBtn dashBtnPrimary" onClick={startOver}>Add more photos</button>
              <button type="button" className="dashBtn" onClick={onClose}>Close</button>
            </div>
          </div>
        </section>
      ) : (
        <>
          <Step number={2} title="Choose the photos">
            <p className="ui-muted">Up to {MAX_FILES} photos at a time, 5 MB each.</p>
            <input
              ref={inputRef}
              type="file"
              accept="image/*"
              multiple
              className="aa-import-input"
              aria-label="Photos to add"
              onChange={choose}
              disabled={busy}
            />
            <button type="button" className={rows.length ? 'dashBtn' : 'dashBtn dashBtnPrimary'} onClick={() => inputRef.current?.click()} disabled={busy}>
              <Upload size={15} style={{ verticalAlign: '-3px' }} /> {rows.length ? 'Choose different photos' : 'Choose photos'}
            </button>
            {phase === 'matching' && <p className="aa-import-status" role="status"><Spinner /> Matching the photos to animals…</p>}
            {error && <div className="ui-error" style={{ marginTop: 12 }} role="alert">{error}</div>}
          </Step>

          {rows.length > 0 && (phase === 'review' || phase === 'uploading') && (
            <Step number={3} title="Check the matches">
              <div className="aa-stat-strip aa-import-stats">
                <div className="aa-stat-card"><div className="num">{rows.length}</div><div className="label">Photos chosen</div></div>
                <div className="aa-stat-card is-good"><div className="num">{counts.matched}</div><div className="label">Matched</div></div>
                <div className={`aa-stat-card${counts.needChoice ? ' is-bad' : ''}`}><div className="num">{counts.needChoice}</div><div className="label">Need an animal</div></div>
                <div className={`aa-stat-card${counts.unusable ? ' is-bad' : ''}`}><div className="num">{counts.unusable}</div><div className="label">Can’t be used</div></div>
              </div>
              {counts.needChoice > 0 && (
                <p className="ui-muted">Choose the animal for the photos marked “Pick the animal” or “No match”, or leave them on “Skip”.</p>
              )}

              <ul className="aa-photo-list">
                {rows.map((r) => {
                  const [howLabel, howBadge] = HOW[r.how] || HOW.none;
                  const picked = r.animalId ? animalsById.get(r.animalId) : null;
                  const candidateIds = new Set(r.candidates.map((c) => c.id));
                  return (
                    <li key={r.key} className="aa-photo-row">
                      <img src={r.url} alt="" className="aa-photo-thumb" />
                      <div className="aa-photo-info">
                        <div className="name">{r.file.name}</div>
                        <span className={`badge ${howBadge}`}>{r.problem || howLabel}</span>
                        {picked && picked.photos > 0 && <span className="ui-muted"> · has {plural(picked.photos, 'photo')}</span>}
                      </div>
                      {!r.problem && (
                        <select
                          className="ui-input aa-photo-select"
                          aria-label={`Animal for ${r.file.name}`}
                          value={r.animalId || ''}
                          onChange={(e) => setAnimalFor(r.key, e.target.value)}
                          disabled={phase === 'uploading'}
                        >
                          <option value="">— Skip this photo —</option>
                          {r.candidates.length > 0 && (
                            <optgroup label="Animals with this name">
                              {r.candidates.map((c) => <option key={c.id} value={c.id}>{animalLabel(c)}</option>)}
                            </optgroup>
                          )}
                          <optgroup label="All animals">
                            {animals.filter((a) => !candidateIds.has(a.id)).map((a) => <option key={a.id} value={a.id}>{animalLabel(a)}</option>)}
                          </optgroup>
                        </select>
                      )}
                    </li>
                  );
                })}
              </ul>

              <label className="aa-photo-main">
                <input type="checkbox" checked={makeMain} onChange={(e) => setMakeMain(e.target.checked)} disabled={phase === 'uploading'} />
                Make each animal’s first new photo its main photo
              </label>

              <div className="aa-import-actions">
                <button type="button" className="dashBtn dashBtnPrimary" onClick={upload} disabled={!ready.length || phase === 'uploading'}>
                  {phase === 'uploading'
                    ? <><Spinner /> Uploading {progress.done + 1 > progress.total ? progress.total : progress.done + 1} of {progress.total}…</>
                    : `Add ${plural(ready.length, 'photo')} to ${plural(animalCount, 'animal')}`}
                </button>
                <button type="button" className="dashBtn" onClick={startOver} disabled={phase === 'uploading'}>Start over</button>
              </div>
            </Step>
          )}
        </>
      )}
    </div>
  );
}
