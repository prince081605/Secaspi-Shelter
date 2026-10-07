import { useMemo, useRef, useState } from 'react';
import { Download, FileSpreadsheet, LoaderCircle, Upload, X } from 'lucide-react';
import {
  adminDownloadAnimalImportTemplate,
  adminImportAnimals,
  adminPreviewAnimalImport,
} from '../../lib/animalsApi';
import useConfirm from '../../lib/useConfirm';
import useIsMobile from '../../lib/useIsMobile';
import DashCard from '../../components/DashCard';

// Rows of the preview table drawn before "Show all" — a full 1,000-row file is a lot of DOM.
const PREVIEW_LIMIT = 100;
// Same idea for the list of problems.
const PROBLEM_LIMIT = 50;

const RESULT_LABELS = { ready: 'Ready', error: 'Needs fixing', duplicate: 'Possible duplicate' };
const RESULT_BADGES = { ready: 'badgeGreen', error: 'badgeOrange', duplicate: 'badgeSky' };

function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

function formatSize(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const plural = (n, word) => `${n.toLocaleString()} ${word}${n === 1 ? '' : 's'}`;

function Spinner() {
  return <LoaderCircle size={16} className="aa-import-spin" aria-hidden="true" />;
}

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

/**
 * Admin-only bulk import of animals from an Excel workbook, in plain steps for shelter staff:
 * download the template, upload the filled-in copy, review every row the server checked, then
 * import. Nothing is saved until the admin confirms; rows with problems and possible duplicates
 * are skipped (a duplicate can be ticked "Import anyway"). Photos are added afterwards, per animal.
 */
export default function AnimalImport({ onImported, onClose }) {
  const confirm = useConfirm();
  const isMobile = useIsMobile();
  const inputRef = useRef(null);
  // idle → checking → preview → importing → done
  const [phase, setPhase] = useState('idle');
  const [file, setFile] = useState(null);
  const [preview, setPreview] = useState(null);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');
  const [allowed, setAllowed] = useState(() => new Set());
  const [filter, setFilter] = useState('all');
  const [showAllRows, setShowAllRows] = useState(false);
  const [showAllProblems, setShowAllProblems] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [templateError, setTemplateError] = useState('');

  const busy = phase === 'checking' || phase === 'importing';
  const rows = useMemo(() => preview?.rows ?? [], [preview]);
  const problemRows = rows.filter((r) => r.status === 'error');
  const duplicateRows = rows.filter((r) => r.status === 'duplicate');
  const importCount = (preview?.summary?.ready ?? 0) + allowed.size;

  const downloadTemplate = async () => {
    setTemplateError('');
    setDownloading(true);
    try {
      const { blob } = await adminDownloadAnimalImportTemplate();
      saveBlob(blob, 'secaspi-animal-import-template.xlsx');
    } catch (err) {
      setTemplateError(err?.message || 'Could not download the template.');
    } finally {
      setDownloading(false);
    }
  };

  const resetReview = () => {
    setPreview(null);
    setResult(null);
    setError('');
    setAllowed(new Set());
    setFilter('all');
    setShowAllRows(false);
    setShowAllProblems(false);
  };

  // Choosing a file checks it straight away — nothing is saved by this step.
  const checkFile = async (chosen) => {
    if (!chosen) return;
    resetReview();
    setFile(chosen);
    setPhase('checking');
    try {
      const data = await adminPreviewAnimalImport(chosen);
      setPreview(data);
      setPhase('preview');
    } catch (err) {
      setError(err?.message || 'The file could not be checked.');
      setPhase('idle');
    }
  };

  const onFileChange = (e) => {
    const chosen = e.target.files?.[0];
    // Clear the input so choosing the same (now corrected) file again still fires a change.
    e.target.value = '';
    checkFile(chosen);
  };

  const startOver = () => {
    resetReview();
    setFile(null);
    setPhase('idle');
  };

  const toggleAllowed = (row) => {
    setAllowed((current) => {
      const next = new Set(current);
      if (next.has(row)) next.delete(row);
      else next.add(row);
      return next;
    });
  };

  const runImport = async () => {
    const skippedProblems = preview.summary.errors;
    const skippedDuplicates = preview.summary.duplicates - allowed.size;
    const ok = await confirm({
      title: `Import ${plural(importCount, 'animal')}?`,
      message:
        'They are added to the shelter straight away, and animals with the status Available appear on the public adoption page. ' +
        'Existing animals are not changed. Photos are added afterwards from Manage → Photos.',
      confirmLabel: `Import ${plural(importCount, 'animal')}`,
      summary: [
        { label: 'Will be imported', value: importCount.toLocaleString() },
        { label: 'Skipped: need fixing', value: skippedProblems ? skippedProblems.toLocaleString() : '' },
        { label: 'Skipped: possible duplicates', value: skippedDuplicates ? skippedDuplicates.toLocaleString() : '' },
      ],
    });
    if (!ok) return;

    setError('');
    setPhase('importing');
    try {
      const data = await adminImportAnimals(file, [...allowed]);
      setResult(data);
      setPhase('done');
      onImported?.();
    } catch (err) {
      setError(err?.message || 'The import failed. No animals were added.');
      // The server re-checks the file on import; if what it found differs from the preview
      // (e.g. someone added one of these animals meanwhile), show its version.
      if (err?.data?.rows) {
        setPreview((p) => ({ ...p, rows: err.data.rows, summary: err.data.summary }));
        setAllowed(new Set());
      }
      setPhase('preview');
    }
  };

  const visibleRows = (filter === 'all' ? rows : rows.filter((r) => r.status === filter));
  const shownRows = showAllRows ? visibleRows : visibleRows.slice(0, PREVIEW_LIMIT);
  const shownProblems = showAllProblems ? problemRows : problemRows.slice(0, PROBLEM_LIMIT);

  const resultBadge = (r) => {
    const willImport = r.status === 'duplicate' && allowed.has(r.row);
    return (
      <span className={`badge ${willImport ? RESULT_BADGES.ready : RESULT_BADGES[r.status]}`} title={r.errors[0] || r.duplicate_of?.message || ''}>
        {willImport ? 'Import anyway' : RESULT_LABELS[r.status]}
      </span>
    );
  };

  return (
    <div className="dashCard aa-import">
      <div className="aa-import-head">
        <div>
          <h3><FileSpreadsheet size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Import animals from Excel</h3>
          <p className="ui-muted">Add many animals at once from a spreadsheet. Every row is checked before anything is saved.</p>
        </div>
        <button type="button" className="dashBtn" onClick={onClose} disabled={phase === 'importing'} aria-label="Close import">
          <X size={14} />
        </button>
      </div>

      <Step number={1} title="Download the template">
        <p className="ui-muted">It has the right columns, dropdowns for the choices, an Example sheet and step-by-step instructions.</p>
        {templateError && <div className="ui-error">{templateError}</div>}
        <button type="button" className="dashBtn" onClick={downloadTemplate} disabled={downloading}>
          {downloading ? <Spinner /> : <Download size={15} style={{ verticalAlign: '-3px' }} />} {downloading ? 'Preparing…' : 'Download Excel template'}
        </button>
      </Step>

      {phase === 'done' && result ? (
        <ImportResult result={result} onAgain={startOver} onClose={onClose} />
      ) : (
        <>
          <Step number={2} title="Upload your filled-in file">
            <p className="ui-muted">Excel workbook (.xlsx), up to 1,000 animals and 5 MB.</p>
            <input
              ref={inputRef}
              type="file"
              accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
              className="aa-import-input"
              aria-label="Excel file to import"
              onChange={onFileChange}
              disabled={busy}
            />
            {file ? (
              <div className="aa-import-file">
                <FileSpreadsheet size={20} aria-hidden="true" />
                <div>
                  <div className="name">{file.name}</div>
                  <div className="ui-muted">{formatSize(file.size)}</div>
                </div>
                <button type="button" className="dashBtn" onClick={() => inputRef.current?.click()} disabled={busy}>
                  Choose a different file
                </button>
              </div>
            ) : (
              <button type="button" className="dashBtn dashBtnPrimary" onClick={() => inputRef.current?.click()} disabled={busy}>
                <Upload size={15} style={{ verticalAlign: '-3px' }} /> Choose Excel file
              </button>
            )}
            {phase === 'checking' && (
              <p className="aa-import-status" role="status"><Spinner /> Checking every row…</p>
            )}
            {error && phase === 'idle' && <div className="ui-error" style={{ marginTop: 12 }} role="alert">{error}</div>}
          </Step>

          {preview && (phase === 'preview' || phase === 'importing') && (
            <Step number={3} title="Check the results">
              {preview.warnings?.length > 0 && (
                <div className="ui-notice">{preview.warnings.map((w) => <div key={w}>{w}</div>)}</div>
              )}

              <div className="aa-stat-strip aa-import-stats">
                <div className="aa-stat-card"><div className="num">{preview.summary.total.toLocaleString()}</div><div className="label">Rows found</div></div>
                <div className="aa-stat-card is-good"><div className="num">{preview.summary.ready.toLocaleString()}</div><div className="label">Ready to import</div></div>
                <div className={`aa-stat-card${preview.summary.errors ? ' is-bad' : ''}`}><div className="num">{preview.summary.errors.toLocaleString()}</div><div className="label">Need fixing</div></div>
                <div className="aa-stat-card"><div className="num">{preview.summary.duplicates.toLocaleString()}</div><div className="label">Possible duplicates</div></div>
              </div>

              {problemRows.length > 0 && (
                <div className="aa-import-block">
                  <h4>Rows that need fixing</h4>
                  <p className="ui-muted">These rows will be skipped. Fix them in Excel and upload the file again, or import the ready rows now and add these later.</p>
                  <ul className="aa-import-list">
                    {shownProblems.flatMap((r) => r.errors.map((message, i) => (
                      <li key={`${r.row}-${i}`}><strong>Row {r.row}:</strong> {message}</li>
                    )))}
                  </ul>
                  {problemRows.length > PROBLEM_LIMIT && (
                    <button type="button" className="dashBtn" onClick={() => setShowAllProblems((v) => !v)}>
                      {showAllProblems ? 'Show fewer' : `Show all ${problemRows.length.toLocaleString()} rows with problems`}
                    </button>
                  )}
                </div>
              )}

              {duplicateRows.length > 0 && (
                <div className="aa-import-block">
                  <h4>Possible duplicates</h4>
                  <p className="ui-muted">
                    These have the same name and species as an animal already in the system, or as an earlier row. They are skipped
                    unless you tick “Import anyway” — for example, two different dogs that are both called Brownie. Nothing already in the system is changed.
                  </p>
                  <ul className="aa-import-list">
                    {duplicateRows.map((r) => (
                      <li key={r.row} className="aa-import-dup">
                        <span><strong>Row {r.row}:</strong> {r.values.name} ({r.values.species}). {r.duplicate_of?.message}</span>
                        <label>
                          <input type="checkbox" checked={allowed.has(r.row)} onChange={() => toggleAllowed(r.row)} disabled={busy} />
                          Import anyway
                        </label>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              <div className="aa-import-block">
                <div className="aa-import-chips" role="group" aria-label="Show rows">
                  {[
                    ['all', 'All rows', rows.length],
                    ['ready', 'Ready', preview.summary.ready],
                    ['error', 'Need fixing', preview.summary.errors],
                    ['duplicate', 'Duplicates', preview.summary.duplicates],
                  ].map(([key, label, count]) => (
                    <button key={key} type="button" className={filter === key ? 'active' : ''} aria-pressed={filter === key}
                      onClick={() => { setFilter(key); setShowAllRows(false); }}>
                      {label} ({count.toLocaleString()})
                    </button>
                  ))}
                </div>

                {shownRows.length === 0 ? (
                  <div className="ui-empty" style={{ margin: '12px 0' }}>No rows here.</div>
                ) : isMobile ? (
                  <div className="dashCardList">
                    {shownRows.map((r) => (
                      <DashCard
                        key={r.row}
                        title={`Row ${r.row}: ${r.values.name || '(no name)'}`}
                        subtitle={[r.values.species, r.values.breed].filter(Boolean).join(' • ')}
                        fields={[
                          { label: 'Result', value: resultBadge(r) },
                          { label: 'Age', value: r.values.age ?? '—' },
                          { label: 'Gender', value: r.values.gender || '—' },
                          { label: 'Size', value: r.values.size || '—' },
                          { label: 'Status', value: r.values.status || '—' },
                          { label: 'Area', value: r.values.location || '—' },
                        ]}
                      />
                    ))}
                  </div>
                ) : (
                  <div className="dashTableWrap">
                    <table className="dashTable aa-import-table">
                      <thead>
                        <tr>
                          <th>Row</th><th>Result</th><th>Name</th><th>Species</th><th>Breed</th><th>Age</th>
                          <th>Gender</th><th>Size</th><th>Weight</th><th>Status</th><th>Area</th>
                        </tr>
                      </thead>
                      <tbody>
                        {shownRows.map((r) => (
                          <tr key={r.row}>
                            <td>{r.row}</td>
                            <td>{resultBadge(r)}</td>
                            <td>{r.values.name || '—'}</td>
                            <td>{r.values.species || '—'}</td>
                            <td>{r.values.breed || '—'}</td>
                            <td>{r.values.age ?? '—'}</td>
                            <td>{r.values.gender || '—'}</td>
                            <td>{r.values.size || '—'}</td>
                            <td>{r.values.weight ?? '—'}</td>
                            <td>{r.values.status || '—'}</td>
                            <td>{r.values.location || '—'}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
                {visibleRows.length > PREVIEW_LIMIT && (
                  <button type="button" className="dashBtn" style={{ marginTop: 8 }} onClick={() => setShowAllRows((v) => !v)}>
                    {showAllRows ? `Show the first ${PREVIEW_LIMIT}` : `Show all ${visibleRows.length.toLocaleString()} rows`}
                  </button>
                )}
              </div>
            </Step>
          )}

          {preview && (phase === 'preview' || phase === 'importing') && (
            <Step number={4} title="Import">
              {error && <div className="ui-error" role="alert">{error}</div>}
              <p className="ui-muted">
                {importCount > 0
                  ? `${plural(importCount, 'animal')} will be added. Rows that need fixing and unticked duplicates are skipped.`
                  : preview.summary.duplicates > 0
                    ? 'No rows are ready to import yet. Tick “Import anyway” on any possible duplicate that is really a different animal, or fix the file and upload it again.'
                    : 'No rows are ready to import yet. Fix the problems above in Excel and upload the file again.'}
              </p>
              <div className="aa-import-actions">
                <button type="button" className="dashBtn dashBtnPrimary" onClick={runImport} disabled={busy || importCount === 0}>
                  {phase === 'importing' ? <><Spinner /> Importing {plural(importCount, 'animal')}…</> : `Import ${plural(importCount, 'animal')}`}
                </button>
                <button type="button" className="dashBtn" onClick={startOver} disabled={busy}>Start over</button>
              </div>
            </Step>
          )}
        </>
      )}
    </div>
  );
}

function ImportResult({ result, onAgain, onClose }) {
  const { summary } = result;
  return (
    <section className="aa-import-step aa-import-result" aria-live="polite">
      <div className="aa-import-step-num" aria-hidden="true">✓</div>
      <div>
        <h3>Import finished</h3>
        <div className="ui-success-msg">
          {plural(summary.imported, 'animal')} {summary.imported === 1 ? 'was' : 'were'} added to the shelter.
        </div>

        <div className="aa-stat-strip aa-import-stats">
          <div className="aa-stat-card"><div className="num">{summary.processed.toLocaleString()}</div><div className="label">Rows processed</div></div>
          <div className="aa-stat-card is-good"><div className="num">{summary.imported.toLocaleString()}</div><div className="label">Imported</div></div>
          <div className={`aa-stat-card${summary.rejected ? ' is-bad' : ''}`}><div className="num">{summary.rejected.toLocaleString()}</div><div className="label">Rejected (need fixing)</div></div>
          <div className="aa-stat-card"><div className="num">{summary.skipped_duplicates.toLocaleString()}</div><div className="label">Skipped duplicates</div></div>
        </div>

        {result.rejected.length > 0 && (
          <div className="aa-import-block">
            <h4>Rejected rows</h4>
            <ul className="aa-import-list">
              {result.rejected.flatMap((r) => r.errors.map((message, i) => (
                <li key={`${r.row}-${i}`}><strong>Row {r.row}:</strong> {message}</li>
              )))}
            </ul>
          </div>
        )}

        {result.skipped.length > 0 && (
          <div className="aa-import-block">
            <h4>Skipped as possible duplicates</h4>
            <ul className="aa-import-list">
              {result.skipped.map((r) => (
                <li key={r.row}><strong>Row {r.row}:</strong> {r.name}. {r.reason}</li>
              ))}
            </ul>
          </div>
        )}

        <p className="ui-muted">
          Next: add photos for the new animals from Manage → Photos. Each animal’s QR code is created automatically.
        </p>
        <div className="aa-import-actions">
          <button type="button" className="dashBtn dashBtnPrimary" onClick={onAgain}>Import another file</button>
          <button type="button" className="dashBtn" onClick={onClose}>Close</button>
        </div>
      </div>
    </section>
  );
}
