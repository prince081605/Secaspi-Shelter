import { useEffect, useState } from 'react';
import { Camera, Stethoscope, ClipboardList, X } from 'lucide-react';
import {
  adminListIntakes,
  adminGetIntake,
  adminCreateIntake,
  adminUpdateIntake,
  adminDeleteIntake,
  adminConvertIntake,
  adminAddIntakeDocuments,
  adminDeleteIntakeDocument,
} from '../../lib/intakesApi';
import StatusBadge from '../../components/StatusBadge';
import ConfirmButton from '../../components/ConfirmButton';
import Pagination from '../../components/Pagination';

// The intake queue (rescue/surrender/stray triage → "Add to Animals" conversion) was extracted
// from AnimalsAdmin.jsx (audit §0.2 / §3 C-1 / §7 C) — a distinct domain that bloated that file
// by ~370 lines. Rendered by AnimalsAdmin above the animal listing.

const GENDERS = ['male', 'female'];
const INTAKE_TYPES = ['rescue', 'owner_surrender', 'stray'];
const INTAKE_STATUSES = ['pending', 'under_assessment', 'approved', 'converted', 'rejected'];

const emptyIntakeForm = {
  intake_type: 'stray',
  reporter_name: '',
  contact_number: '',
  location: '',
  animal_name: '',
  species: '',
  breed: '',
  estimated_age: '',
  gender: '',
  description: '',
};

function photoSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

function NewIntakeForm({ onCancel, onCreated }) {
  const [form, setForm] = useState(emptyIntakeForm);
  const [files, setFiles] = useState(null);
  const [state, setState] = useState({ status: 'idle', error: '' });

  const setField = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    setState({ status: 'loading', error: '' });
    try {
      const fd = new FormData();
      Object.entries(form).forEach(([k, v]) => { if (v) fd.append(k, v); });
      if (files) {
        Array.from(files).forEach((file) => fd.append('documents[]', file));
      }
      await adminCreateIntake(fd);
      onCreated();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to create intake.' });
    }
  };

  return (
    <form onSubmit={handleSubmit} className="dashCard" style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      <div className="dashFormGrid">
        <div className="ui-field">
          <label className="ui-label">Intake type</label>
          <select className="ui-input" value={form.intake_type} onChange={setField('intake_type')}>
            {INTAKE_TYPES.map((t) => <option key={t} value={t}>{t.replace('_', ' ')}</option>)}
          </select>
        </div>
        <div className="ui-field">
          <label className="ui-label">Reporter / surrenderer name</label>
          <input className="ui-input" value={form.reporter_name} onChange={setField('reporter_name')} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Contact number</label>
          <input className="ui-input" value={form.contact_number} onChange={setField('contact_number')} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Location</label>
          <input className="ui-input" value={form.location} onChange={setField('location')} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Animal name (if known)</label>
          <input className="ui-input" value={form.animal_name} onChange={setField('animal_name')} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Species</label>
          <input className="ui-input" value={form.species} onChange={setField('species')} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Breed</label>
          <input className="ui-input" value={form.breed} onChange={setField('breed')} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Estimated age</label>
          <input className="ui-input" placeholder="e.g. ~2 years" value={form.estimated_age} onChange={setField('estimated_age')} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Gender</label>
          <select className="ui-input" value={form.gender} onChange={setField('gender')}>
            <option value="">—</option>
            {GENDERS.map((g) => <option key={g} value={g}>{g}</option>)}
          </select>
        </div>
      </div>
      <div className="ui-field">
        <label className="ui-label">Description / circumstances</label>
        <textarea className="ui-input" rows={3} value={form.description} onChange={setField('description')} />
      </div>
      <div className="ui-field">
        <label className="ui-label">Animal photo(s)</label>
        <input type="file" accept="image/*" multiple onChange={(e) => setFiles(e.target.files)} />
        <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>
          These carry over as the animal's photos when you add this intake to Animals.
        </div>
      </div>
      <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
        <button className="ui-btn-primary" type="submit" disabled={state.status === 'loading'}>
          {state.status === 'loading' ? 'Saving…' : 'Create intake'}
        </button>
        <button type="button" className="dashBtn" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

function AssessmentPanel({ intake, onChanged }) {
  const [detail, setDetail] = useState(null);
  const [status, setStatus] = useState(intake.status);
  const [notes, setNotes] = useState('');
  const [assessedBy, setAssessedBy] = useState('');
  const [assessmentDate, setAssessmentDate] = useState('');
  const [files, setFiles] = useState(null);
  const [state, setState] = useState({ status: 'loading', error: '' });

  const load = () => {
    adminGetIntake(intake.id)
      .then((data) => {
        const i = data?.intake;
        setDetail(i);
        setStatus(i.status);
        setNotes(i.assessment_notes || '');
        setAssessedBy(i.assessed_by || '');
        setAssessmentDate(i.assessment_date || '');
        setState({ status: 'idle', error: '' });
      })
      .catch((err) => setState({ status: 'error', error: err?.message || 'Failed to load intake.' }));
  };

  useEffect(load, [intake.id]);

  const save = async () => {
    setState((s) => ({ ...s, status: 'loading' }));
    try {
      await adminUpdateIntake(intake.id, {
        status: status === 'converted' ? undefined : status,
        assessment_notes: notes || null,
        assessed_by: assessedBy || null,
        assessment_date: assessmentDate || null,
      });
      load();
      onChanged();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to save.' });
    }
  };

  const convert = async () => {
    setState((s) => ({ ...s, status: 'loading' }));
    try {
      await adminConvertIntake(intake.id);
      load();
      onChanged();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to convert.' });
    }
  };

  const uploadDocs = async () => {
    if (!files || files.length === 0) return;
    setState((s) => ({ ...s, status: 'loading' }));
    try {
      const fd = new FormData();
      Array.from(files).forEach((f) => fd.append('documents[]', f));
      await adminAddIntakeDocuments(intake.id, fd);
      setFiles(null);
      load();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to upload documents.' });
    }
  };

  const deleteDoc = async (docId) => {
    try {
      await adminDeleteIntakeDocument(intake.id, docId);
      load();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to delete document.' });
    }
  };

  if (!detail) return state.error ? <div className="ui-error">{state.error}</div> : <div className="ui-empty">Loading…</div>;

  return (
    <div style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      <div className="dashFormGrid">
        <div><strong>Contact:</strong> {detail.contact_number || '—'}</div>
        <div><strong>Location:</strong> {detail.location || '—'}</div>
        <div><strong>Breed:</strong> {detail.breed || '—'}</div>
        <div><strong>Age:</strong> {detail.estimated_age || '—'}</div>
      </div>
      <div style={{ marginTop: 6 }}><strong>Description:</strong> {detail.description || '—'}</div>

      <div className="dashSectionTitle" style={{ fontSize: 13, marginTop: 12 }}><Camera size={15} style={{ verticalAlign: '-3px', marginRight: 6 }} />Photos</div>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {detail.documents.map((d) => (
          <div key={d.id} style={{ position: 'relative', border: '1px solid var(--line)', borderRadius: 8, overflow: 'hidden' }}>
            <a href={photoSrc(d.file_path)} target="_blank" rel="noreferrer" title={d.original_name || `Photo #${d.id}`}>
              <img src={photoSrc(d.file_path)} alt={d.original_name || `Photo #${d.id}`} style={{ width: 96, height: 96, objectFit: 'cover', display: 'block' }} />
            </a>
            <button className="dashBtn dashBtnDanger" style={{ position: 'absolute', top: 2, right: 2, padding: '2px 4px' }} aria-label="Delete photo" onClick={() => deleteDoc(d.id)}><X size={12} /></button>
          </div>
        ))}
        {detail.documents.length === 0 && <div className="ui-empty">No photos uploaded.</div>}
      </div>
      <div style={{ display: 'flex', gap: 8, marginTop: 6 }}>
        <input type="file" accept="image/*" multiple onChange={(e) => setFiles(e.target.files)} />
        <button className="dashBtn" type="button" onClick={uploadDocs}>Upload</button>
      </div>

      <div className="dashSectionTitle" style={{ fontSize: 13, marginTop: 12 }}><Stethoscope size={15} style={{ verticalAlign: '-3px', marginRight: 6 }} />Assessment</div>
      <div className="dashFormGrid">
        <div className="ui-field">
          <label className="ui-label">Status</label>
          <select className="ui-input" value={status} disabled={detail.status === 'converted'} onChange={(e) => setStatus(e.target.value)}>
            {INTAKE_STATUSES.filter((s) => s !== 'converted').map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
          </select>
        </div>
        <div className="ui-field">
          <label className="ui-label">Assessed by</label>
          <input className="ui-input" value={assessedBy} onChange={(e) => setAssessedBy(e.target.value)} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Assessment date</label>
          <input className="ui-input" type="date" value={assessmentDate || ''} onChange={(e) => setAssessmentDate(e.target.value)} />
        </div>
        <div className="ui-field" style={{ gridColumn: '1 / -1' }}>
          <label className="ui-label">Notes</label>
          <textarea className="ui-input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
      </div>
      <div style={{ display: 'flex', gap: 8 }}>
        {detail.status !== 'converted' && (
          <button className="dashBtn dashBtnPrimary" type="button" onClick={save} disabled={state.status === 'loading'}>
            {state.status === 'loading' ? 'Saving…' : 'Save assessment'}
          </button>
        )}
        {detail.status !== 'converted' && (
          <button className="dashBtn dashBtnPrimary" type="button" onClick={convert} disabled={state.status === 'loading'}>
            Add to Animals
          </button>
        )}
        {detail.status === 'converted' && (
          <span style={{ color: 'var(--muted)' }}>Converted to animal #{detail.converted_animal_id}</span>
        )}
      </div>
    </div>
  );
}

function IntakeRow({ intake, onChanged }) {
  const [expanded, setExpanded] = useState(false);
  const [error, setError] = useState('');

  const remove = async () => {
    setError('');
    try {
      await adminDeleteIntake(intake.id);
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to delete intake.');
    }
  };

  const addToAnimals = async () => {
    setError('');
    try {
      await adminConvertIntake(intake.id);
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to add this intake to Animals.');
    }
  };

  return (
    <>
      <tr>
        <td>{intake.intake_type.replace('_', ' ')}</td>
        <td>{intake.animal_name || '—'} {intake.species ? `(${intake.species})` : ''}</td>
        <td>{intake.reporter_name || '—'}</td>
        <td><StatusBadge status={intake.status} /></td>
        <td>{(intake.created_at || '').slice(0, 10)}</td>
        <td style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          <button className="dashBtn" onClick={() => setExpanded((v) => !v)}>{expanded ? 'Hide' : 'Details'}</button>
          {intake.status === 'converted' ? (
            <span style={{ color: 'var(--muted)', fontSize: 12, alignSelf: 'center' }}>
              → Animal #{intake.converted_animal_id}
            </span>
          ) : (
            <ConfirmButton confirmLabel="Add this intake to Animals?" onConfirm={addToAnimals}>Add to Animals</ConfirmButton>
          )}
          <ConfirmButton confirmLabel="Delete intake?" onConfirm={remove}>Delete</ConfirmButton>
        </td>
      </tr>
      {error && (
        <tr><td colSpan={6}><div className="ui-error">{error}</div></td></tr>
      )}
      {expanded && (
        <tr>
          <td colSpan={6} className="dashExpandPanel">
            <AssessmentPanel intake={intake} onChanged={onChanged} />
          </td>
        </tr>
      )}
    </>
  );
}

export default function IntakesAdmin({ onConverted }) {
  const [intakes, setIntakes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [intakeType, setIntakeType] = useState('');
  const [status, setStatusFilter] = useState('');
  const [showNew, setShowNew] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  // Either filter starting a fresh result set jumps back to page 1.
  const changeType = (value) => { setPage(1); setIntakeType(value); };
  const changeStatus = (value) => { setPage(1); setStatusFilter(value); };

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListIntakes({ intake_type: intakeType, status, page })
      .then((data) => {
        if (!mounted) return;
        setIntakes(data?.data || []);
        setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load intakes.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [intakeType, status, refreshKey, page]);

  // Keep the current page on refresh so an open assessment panel isn't collapsed.
  const refresh = () => {
    setShowNew(false);
    setRefreshKey((k) => k + 1);
    onConverted?.();
  };

  return (
    <>
      <div className="dashSectionTitle"><ClipboardList size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Intake Queue</div>
      {error && <div className="ui-error">{error}</div>}

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 8 }}>
        <select className="ui-input" style={{ maxWidth: 160 }} aria-label="Filter intakes by type" value={intakeType} onChange={(e) => changeType(e.target.value)}>
          <option value="">All types</option>
          {INTAKE_TYPES.map((t) => <option key={t} value={t}>{t.replace('_', ' ')}</option>)}
        </select>
        <select className="ui-input" style={{ maxWidth: 160 }} aria-label="Filter intakes by status" value={status} onChange={(e) => changeStatus(e.target.value)}>
          <option value="">Active (excl. converted)</option>
          {INTAKE_STATUSES.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
        </select>
        <button className="dashBtn dashBtnPrimary" onClick={() => setShowNew((v) => !v)}>
          {showNew ? 'Close' : '+ Add intake'}
        </button>
      </div>

      {showNew && <NewIntakeForm onCancel={() => setShowNew(false)} onCreated={refresh} />}

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : intakes.length === 0 ? (
        <div className="ui-empty">No intakes match this filter.</div>
      ) : (
        <div className="dashTableWrap" style={{ marginTop: 10 }}>
          <table className="dashTable">
            <thead>
              <tr>
                <th>Type</th>
                <th>Animal</th>
                <th>Reporter</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {intakes.map((i) => (
                <IntakeRow key={i.id} intake={i} onChanged={refresh} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && intakes.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}
