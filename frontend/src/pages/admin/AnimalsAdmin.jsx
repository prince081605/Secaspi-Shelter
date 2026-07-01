import { Fragment, useEffect, useState } from 'react';
import {
  adminListAnimals,
  adminGetAnimalStats,
  adminGetAnimal,
  adminCreateAnimal,
  adminUpdateAnimal,
  adminArchiveAnimal,
  adminDeleteAnimal,
  adminAddAnimalPhotos,
  adminDeleteAnimalPhoto,
  adminCreateMedicalRecord,
  adminDeleteMedicalRecord,
  adminCreateVaccination,
  adminDeleteVaccination,
} from '../../lib/animalsApi';
import StatusBadge from '../../components/StatusBadge';
import TypeToConfirmButton from '../../components/TypeToConfirmButton';
import Pagination from '../../components/Pagination';
import IntakesAdmin from './IntakesAdmin';
import './AnimalsAdmin.css';

const STATUSES = ['available', 'adopted', 'fostered', 'medical', 'quarantine', 'archived'];
const GENDERS = ['male', 'female'];
const SIZES = ['small', 'medium', 'large'];
const RECORD_TYPES = ['vaccination', 'deworming', 'treatment', 'surgery', 'checkup', 'emergency'];

const BEHAVIORAL_ISSUES = [
  'separation anxiety',
  'aggression & resource guarding',
  'dog-to-dog aggression',
  'territorial aggression',
  'fear aggression',
  'destructive chewing & digging',
  'inappropriate elimination',
  'excessive barking',
  'excessive vocalization',
  'jumping/mouthing',
  'pulling on leash',
  'excessive energy',
  'extreme shyness',
  'fear of strangers',
  'fear of loud noises',
  'post-trauma/trust issues',
  'pain-related aggression',
  'cognitive issues (senior)',
];

const emptyForm = {
  name: '',
  species: '',
  breed: '',
  age: '',
  gender: '',
  size: '',
  weight: '',
  status: 'available',
  rescue_story: '',
  behavioral_assessment: [],
};

function photoSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

function AnimalForm({ initial, onCancel, onSaved }) {
  const isEdit = Boolean(initial?.id);
  const [form, setForm] = useState(() => {
    if (!initial) return emptyForm;
    // The API returns null for unset nullable fields (age, breed, gender, ...), but a null
    // `value` on a controlled <input> makes React treat it as uncontrolled, then flips it to
    // controlled on the first keystroke — dropping that keystroke. Coerce nulls to the empty
    // strings emptyForm already uses so every field stays controlled from the first render.
    const merged = { ...emptyForm, ...initial };
    for (const key of Object.keys(emptyForm)) {
      if (merged[key] === null || merged[key] === undefined) merged[key] = emptyForm[key];
    }
    return merged;
  });
  const [photoFiles, setPhotoFiles] = useState(null);
  const [state, setState] = useState({ status: 'idle', error: '' });
  // Set when a create hits a duplicate (same name + species). Holds the existing animal so
  // we can preview it inline alongside an explicit "add anyway" override. Previewing is
  // non-destructive — it must never discard the in-progress form the admin is filling out.
  const [duplicate, setDuplicate] = useState(null);
  const [showExisting, setShowExisting] = useState(false);
  // Bumped on Clear to remount the (uncontrolled) file input so its chosen-filename label resets.
  const [fileInputKey, setFileInputKey] = useState(0);

  // The list row (`initial`) only carries a subset of fields — notably it omits weight,
  // rescue_story and behavioral_assessment — so editing straight from it leaves those blank
  // and would wipe them on save. Hydrate the form from the full record once the form opens.
  useEffect(() => {
    if (!initial?.id) return;
    let active = true;
    adminGetAnimal(initial.id)
      .then((data) => {
        const full = data?.animal;
        if (!active || !full) return;
        const merged = { ...emptyForm, ...full };
        for (const key of Object.keys(emptyForm)) {
          if (merged[key] === null || merged[key] === undefined) merged[key] = emptyForm[key];
        }
        setForm(merged);
      })
      .catch(() => { /* keep the list-row values already shown */ });
    return () => { active = false; };
  }, [initial?.id]);

  const setField = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  const toggleBehavioralIssue = (issue) => {
    setForm((f) => {
      const current = Array.isArray(f.behavioral_assessment) ? f.behavioral_assessment : [];
      const updated = current.includes(issue)
        ? current.filter((i) => i !== issue)
        : [...current, issue];
      return { ...f, behavioral_assessment: updated };
    });
  };

  const submit = async (force) => {
    setState({ status: 'loading', error: '' });
    setDuplicate(null);
    setShowExisting(false);
    try {
      if (isEdit) {
        await adminUpdateAnimal(initial.id, {
          name: form.name,
          species: form.species,
          breed: form.breed || null,
          age: form.age === '' ? null : Number(form.age),
          gender: form.gender || null,
          size: form.size || null,
          weight: form.weight === '' ? null : Number(form.weight),
          status: form.status,
          rescue_story: form.rescue_story || null,
          behavioral_assessment: form.behavioral_assessment && form.behavioral_assessment.length > 0 ? form.behavioral_assessment : null,
        });
      } else {
        const fd = new FormData();
        fd.append('name', form.name);
        fd.append('species', form.species);
        if (form.breed) fd.append('breed', form.breed);
        if (form.age !== '') fd.append('age', form.age);
        if (form.gender) fd.append('gender', form.gender);
        if (form.size) fd.append('size', form.size);
        if (form.weight !== '') fd.append('weight', form.weight);
        fd.append('status', form.status);
        if (form.rescue_story) fd.append('rescue_story', form.rescue_story);
        if (form.behavioral_assessment && form.behavioral_assessment.length > 0) {
          fd.append('behavioral_assessment', JSON.stringify(form.behavioral_assessment));
        }
        if (photoFiles) {
          Array.from(photoFiles).forEach((file) => fd.append('photos[]', file));
        }
        if (force) fd.append('force', '1');
        await adminCreateAnimal(fd);
      }
      setState({ status: 'success', error: '' });
      onSaved();
    } catch (err) {
      // A 409 on create means a same name + species animal already exists. Surface the
      // existing record (View shortcut + "add anyway") instead of a plain error.
      if (!isEdit && err?.status === 409 && err?.data?.existing_animal) {
        setDuplicate(err.data.existing_animal);
        setState({ status: 'idle', error: err.message || '' });
      } else {
        setState({ status: 'error', error: err?.message || 'Failed to save animal.' });
      }
    }
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    submit(false);
  };

  // Reset every field back to empty without closing the form, so the admin can start over.
  const clearForm = () => {
    setForm(emptyForm);
    setPhotoFiles(null);
    setDuplicate(null);
    setShowExisting(false);
    setState({ status: 'idle', error: '' });
    setFileInputKey((k) => k + 1);
  };

  return (
    <form onSubmit={handleSubmit} className="dashCard" style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      {duplicate && (
        <div className="ui-error" style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          <span>{state.error || `A ${duplicate.species} named "${duplicate.name}" already exists.`}</span>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button type="button" className="dashBtn" onClick={() => setShowExisting((v) => !v)}>
              {showExisting ? 'Hide existing' : 'View existing'}
            </button>
            <button
              type="button"
              className="dashBtn dashBtnPrimary"
              onClick={() => submit(true)}
              disabled={state.status === 'loading'}
            >
              Yes, add {form.species || 'animal'} "{form.name}" anyway
            </button>
          </div>
          {showExisting && (
            <div className="dashCard" style={{ display: 'flex', gap: 12, alignItems: 'center', marginTop: 4 }}>
              <div className="aa-row-photo" aria-hidden="true">
                {duplicate.photo ? <img src={photoSrc(duplicate.photo)} alt="" /> : '🐾'}
              </div>
              <div style={{ fontSize: 13, lineHeight: 1.5 }}>
                <div style={{ fontWeight: 600 }}>{duplicate.name}</div>
                <div>
                  {duplicate.species}
                  {duplicate.breed ? ` • ${duplicate.breed}` : ''}
                  {duplicate.age ? ` • ${duplicate.age} yrs` : ''}
                </div>
                <div>Status: {duplicate.status}</div>
              </div>
            </div>
          )}
        </div>
      )}
      <div className="dashFormGrid">
        <div className="ui-field">
          <label className="ui-label ui-label-required">Name</label>
          <input className="ui-input" value={form.name} onChange={setField('name')} required />
        </div>
        <div className="ui-field">
          <label className="ui-label ui-label-required">Species</label>
          <input className="ui-input" value={form.species} onChange={setField('species')} required />
        </div>
        <div className="ui-field">
          <label className="ui-label">Breed</label>
          <input className="ui-input" value={form.breed} onChange={setField('breed')} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Age (years)</label>
          <input className="ui-input" type="number" min="0" value={form.age} onChange={setField('age')} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Gender</label>
          <select className="ui-input" value={form.gender} onChange={setField('gender')}>
            <option value="">—</option>
            {GENDERS.map((g) => <option key={g} value={g}>{g}</option>)}
          </select>
        </div>
        <div className="ui-field">
          <label className="ui-label">Size</label>
          <select className="ui-input" value={form.size} onChange={setField('size')}>
            <option value="">—</option>
            {SIZES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>
        <div className="ui-field">
          <label className="ui-label">Weight (kg)</label>
          <input className="ui-input" type="number" min="0" step="0.1" value={form.weight} onChange={setField('weight')} />
        </div>
        <div className="ui-field">
          <label className="ui-label">Status</label>
          <select className="ui-input" value={form.status} onChange={setField('status')}>
            {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>
      </div>
      <div className="ui-field">
        <label className="ui-label">Rescue story</label>
        <textarea className="ui-input" rows={3} value={form.rescue_story} onChange={setField('rescue_story')} />
      </div>
      <div className="ui-field">
        <label className="ui-label">Behavioral issues</label>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '0.8rem', marginTop: '0.5rem' }}>
          {BEHAVIORAL_ISSUES.map((issue) => (
            <label key={issue} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer', fontSize: '0.9rem' }}>
              <input
                type="checkbox"
                checked={form.behavioral_assessment.includes(issue)}
                onChange={() => toggleBehavioralIssue(issue)}
              />
              <span style={{ textTransform: 'capitalize' }}>{issue}</span>
            </label>
          ))}
        </div>
      </div>
      {!isEdit && (
        <div className="ui-field">
          <label className="ui-label">Photos</label>
          <input key={fileInputKey} type="file" accept="image/*" multiple onChange={(e) => setPhotoFiles(e.target.files)} />
        </div>
      )}
      <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
        <button className="ui-btn-primary" type="submit" disabled={state.status === 'loading'}>
          {state.status === 'loading' ? 'Saving…' : isEdit ? 'Save changes' : 'Add animal'}
        </button>
        <button type="button" className="dashBtn" onClick={clearForm} disabled={state.status === 'loading'}>Clear</button>
        <button type="button" className="dashBtn" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

function PhotoManager({ animalId, onChanged }) {
  const [photos, setPhotos] = useState([]);
  const [files, setFiles] = useState(null);
  const [state, setState] = useState({ status: 'loading', error: '' });

  const load = () => {
    setState((s) => ({ ...s, status: 'loading' }));
    adminGetAnimal(animalId)
      .then((data) => {
        setPhotos(data?.animal?.photos || []);
        setState({ status: 'idle', error: '' });
      })
      .catch((err) => setState({ status: 'error', error: err?.message || 'Failed to load photos.' }));
  };

  useEffect(load, [animalId]);

  const handleUpload = async () => {
    if (!files || files.length === 0) return;
    setState({ status: 'loading', error: '' });
    try {
      const fd = new FormData();
      Array.from(files).forEach((file) => fd.append('photos[]', file));
      await adminAddAnimalPhotos(animalId, fd);
      setFiles(null);
      load();
      onChanged();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to upload photos.' });
    }
  };

  const handleDelete = async (photoId) => {
    try {
      await adminDeleteAnimalPhoto(animalId, photoId);
      load();
      onChanged();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to delete photo.' });
    }
  };

  return (
    <div style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {photos.map((p) => (
          <div key={p.id} style={{ position: 'relative' }}>
            <img src={photoSrc(p.photo_url)} alt="" className="dashThumbLg" />
            <button
              type="button"
              className="dashBtn dashBtnDanger"
              style={{ position: 'absolute', top: -6, right: -6, padding: '2px 6px', fontSize: 11 }}
              aria-label="Delete photo"
              onClick={() => handleDelete(p.id)}
            >
              ✕
            </button>
          </div>
        ))}
      </div>
      <div style={{ display: 'flex', gap: 8, marginTop: 8, alignItems: 'center' }}>
        <input type="file" accept="image/*" multiple onChange={(e) => setFiles(e.target.files)} />
        <button type="button" className="dashBtn" onClick={handleUpload} disabled={state.status === 'loading'}>
          {state.status === 'loading' ? 'Uploading…' : 'Upload'}
        </button>
      </div>
    </div>
  );
}

function QrCodeViewer({ animalId }) {
  const [qrCode, setQrCode] = useState(null);
  const [animalName, setAnimalName] = useState('');
  const [state, setState] = useState({ status: 'loading', error: '' });

  useEffect(() => {
    adminGetAnimal(animalId)
      .then((data) => {
        setQrCode(data?.animal?.qr_code || null);
        setAnimalName(data?.animal?.name || '');
        setState({ status: 'idle', error: '' });
      })
      .catch((err) => setState({ status: 'error', error: err?.message || 'Failed to load QR code.' }));
  }, [animalId]);

  if (state.status === 'error') return <div className="ui-error" style={{ marginTop: 10 }}>{state.error}</div>;
  if (state.status === 'loading') return <div className="ui-empty" style={{ marginTop: 10 }}>Loading…</div>;
  if (!qrCode) return <div className="ui-empty" style={{ marginTop: 10 }}>No QR code available.</div>;

  return (
    <div style={{ marginTop: 10, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8 }}>
      <img src={photoSrc(qrCode)} alt={`QR code for ${animalName}`} style={{ width: 140, height: 140 }} />
      <a href={photoSrc(qrCode)} download={`${animalName || 'animal'}-qr.svg`} style={{ fontSize: 13, color: 'var(--brand)' }}>
        Download QR
      </a>
    </div>
  );
}

const emptyRecordForm = { type: 'checkup', description: '', vet_name: '', cost: '', record_date: '', follow_up_date: '', notes: '' };
const emptyVaccinationForm = { vaccine_name: '', date_given: '', next_due: '' };

function MedicalManager({ animalId, onChanged }) {
  const [records, setRecords] = useState([]);
  const [vaccinations, setVaccinations] = useState([]);
  const [state, setState] = useState({ status: 'loading', error: '' });
  const [recordForm, setRecordForm] = useState(emptyRecordForm);
  const [vaccinationForm, setVaccinationForm] = useState(emptyVaccinationForm);

  const load = () => {
    setState((s) => ({ ...s, status: 'loading' }));
    adminGetAnimal(animalId)
      .then((data) => {
        setRecords(data?.animal?.medical_records || []);
        setVaccinations(data?.animal?.vaccinations || []);
        setState({ status: 'idle', error: '' });
      })
      .catch((err) => setState({ status: 'error', error: err?.message || 'Failed to load medical history.' }));
  };

  useEffect(load, [animalId]);

  const handleAddRecord = async (e) => {
    e.preventDefault();
    setState((s) => ({ ...s, status: 'loading' }));
    try {
      await adminCreateMedicalRecord(animalId, {
        ...recordForm,
        cost: recordForm.cost === '' ? null : Number(recordForm.cost),
        follow_up_date: recordForm.follow_up_date || null,
      });
      setRecordForm(emptyRecordForm);
      load();
      onChanged();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to add medical record.' });
    }
  };

  const handleDeleteRecord = async (id) => {
    try {
      await adminDeleteMedicalRecord(id);
      load();
      onChanged();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to delete medical record.' });
    }
  };

  const handleAddVaccination = async (e) => {
    e.preventDefault();
    setState((s) => ({ ...s, status: 'loading' }));
    try {
      await adminCreateVaccination(animalId, {
        ...vaccinationForm,
        next_due: vaccinationForm.next_due || null,
      });
      setVaccinationForm(emptyVaccinationForm);
      load();
      onChanged();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to add vaccination.' });
    }
  };

  const handleDeleteVaccination = async (id) => {
    try {
      await adminDeleteVaccination(id);
      load();
      onChanged();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to delete vaccination.' });
    }
  };

  return (
    <div style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}

      <div className="dashSectionTitle" style={{ fontSize: 13, marginTop: 6 }}>💉 Medical records</div>
      {records.length === 0 ? (
        <div className="ui-empty">No medical records yet.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr><th>Type</th><th>Description</th><th>Vet</th><th>Cost</th><th>Date</th><th>Follow-up</th><th></th></tr>
            </thead>
            <tbody>
              {records.map((r) => (
                <tr key={r.id}>
                  <td>{r.type}</td>
                  <td>{r.description || '—'}</td>
                  <td>{r.vet_name || '—'}</td>
                  <td>{r.cost ?? '—'}</td>
                  <td>{r.record_date}</td>
                  <td>{r.follow_up_date || '—'}</td>
                  <td><button className="dashBtn dashBtnDanger" aria-label="Delete medical record" onClick={() => handleDeleteRecord(r.id)}>✕</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <form onSubmit={handleAddRecord} style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8, alignItems: 'flex-end' }}>
        <select className="ui-input" style={{ maxWidth: 130 }} value={recordForm.type} onChange={(e) => setRecordForm((f) => ({ ...f, type: e.target.value }))}>
          {RECORD_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <input className="ui-input" style={{ maxWidth: 160 }} placeholder="Description" value={recordForm.description} onChange={(e) => setRecordForm((f) => ({ ...f, description: e.target.value }))} />
        <input className="ui-input" style={{ maxWidth: 120 }} placeholder="Vet name" value={recordForm.vet_name} onChange={(e) => setRecordForm((f) => ({ ...f, vet_name: e.target.value }))} />
        <input className="ui-input" style={{ maxWidth: 90 }} type="number" min="0" step="0.01" placeholder="Cost" value={recordForm.cost} onChange={(e) => setRecordForm((f) => ({ ...f, cost: e.target.value }))} />
        <input className="ui-input" style={{ maxWidth: 140 }} type="date" required aria-label="Record date (required)" value={recordForm.record_date} onChange={(e) => setRecordForm((f) => ({ ...f, record_date: e.target.value }))} />
        <input className="ui-input" style={{ maxWidth: 150 }} type="date" aria-label="Follow-up date (optional — creates a reminder)" title="Optional follow-up date — auto-creates a health reminder" value={recordForm.follow_up_date} onChange={(e) => setRecordForm((f) => ({ ...f, follow_up_date: e.target.value }))} />
        <button className="dashBtn dashBtnPrimary" type="submit" disabled={state.status === 'loading'}>+ Add</button>
      </form>

      <div className="dashSectionTitle" style={{ fontSize: 13, marginTop: 14 }}>💊 Vaccinations</div>
      {vaccinations.length === 0 ? (
        <div className="ui-empty">No vaccinations recorded yet.</div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr><th>Vaccine</th><th>Date given</th><th>Next due</th><th></th></tr>
            </thead>
            <tbody>
              {vaccinations.map((v) => (
                <tr key={v.id}>
                  <td>{v.vaccine_name}</td>
                  <td>{v.date_given}</td>
                  <td>{v.next_due || '—'}</td>
                  <td><button className="dashBtn dashBtnDanger" aria-label="Delete vaccination record" onClick={() => handleDeleteVaccination(v.id)}>✕</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <form onSubmit={handleAddVaccination} style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8, alignItems: 'flex-end' }}>
        <input className="ui-input" style={{ maxWidth: 160 }} placeholder="Vaccine name *" required value={vaccinationForm.vaccine_name} onChange={(e) => setVaccinationForm((f) => ({ ...f, vaccine_name: e.target.value }))} />
        <input className="ui-input" style={{ maxWidth: 140 }} type="date" required aria-label="Date given (required)" value={vaccinationForm.date_given} onChange={(e) => setVaccinationForm((f) => ({ ...f, date_given: e.target.value }))} />
        <input className="ui-input" style={{ maxWidth: 140 }} type="date" value={vaccinationForm.next_due} onChange={(e) => setVaccinationForm((f) => ({ ...f, next_due: e.target.value }))} />
        <button className="dashBtn dashBtnPrimary" type="submit" disabled={state.status === 'loading'}>+ Add</button>
      </form>
    </div>
  );
}

export default function AnimalsAdmin() {
  const [animals, setAnimals] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [filters, setFilters] = useState({ q: '', species: '', status: '', size: '' });
  const [showCreate, setShowCreate] = useState(false);
  const [editingAnimal, setEditingAnimal] = useState(null);
  const [photosOpenFor, setPhotosOpenFor] = useState(null);
  const [medicalOpenFor, setMedicalOpenFor] = useState(null);
  const [qrOpenFor, setQrOpenFor] = useState(null);
  const [refreshKey, setRefreshKey] = useState(0);
  const [viewMode, setViewMode] = useState('table');
  const [stats, setStats] = useState({ total: 0, available: 0, adopted: 0, archived: 0 });
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  // Update a filter field and jump back to page 1 (a new filter has its own page set).
  const updateFilter = (key) => (value) => {
    setPage(1);
    setFilters((f) => ({ ...f, [key]: value }));
  };

  useEffect(() => {
    let mounted = true;
    // Debounce the list request (300ms) so typing in the search box fires one call, not one per
    // keystroke — matching the public Adoption page (audit §3 B-1).
    const timer = setTimeout(() => {
      setLoading(true);
      adminListAnimals({ ...filters, page })
        .then((data) => {
          if (!mounted) return;
          setAnimals(data?.data || []);
          setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
          setError('');
        })
        .catch((err) => {
          if (!mounted) return;
          setError(err?.message || 'Failed to load animals.');
        })
        .finally(() => {
          if (mounted) setLoading(false);
        });
    }, 300);
    return () => { mounted = false; clearTimeout(timer); };
  }, [filters, refreshKey, page]);

  // Stat strip reflects true totals across all animals (not just the current filtered page),
  // fetched in one grouped-count request instead of 4 full-list calls (audit §3).
  useEffect(() => {
    let mounted = true;
    adminGetAnimalStats()
      .then((data) => {
        if (!mounted) return;
        setStats({
          total: data?.total ?? 0,
          available: data?.available ?? 0,
          adopted: data?.adopted ?? 0,
          archived: data?.archived ?? 0,
        });
      })
      .catch(() => {});
    return () => { mounted = false; };
  }, [refreshKey]);

  const refresh = () => {
    setShowCreate(false);
    setEditingAnimal(null);
    // Keep the current page so an open Photos/Medical/QR panel isn't collapsed; only filter
    // changes reset the page (see updateFilter).
    setRefreshKey((k) => k + 1);
  };

  const handleArchive = async (animal) => {
    try {
      await adminArchiveAnimal(animal.id);
      refresh();
    } catch (err) {
      setError(err?.message || 'Failed to archive animal.');
    }
  };

  const handleRestore = async (animal) => {
    try {
      await adminUpdateAnimal(animal.id, { status: 'available' });
      refresh();
    } catch (err) {
      setError(err?.message || 'Failed to restore animal.');
    }
  };

  const handleDelete = async (animal) => {
    try {
      await adminDeleteAnimal(animal.id);
      refresh();
    } catch (err) {
      setError(err?.message || 'Failed to delete animal.');
    }
  };

  const renderActions = (a) => (
    <>
      <button className="dashBtn" onClick={() => { setEditingAnimal(a); setShowCreate(false); }}>Edit</button>
      <button className="dashBtn" onClick={() => setPhotosOpenFor(photosOpenFor === a.id ? null : a.id)}>Photos</button>
      <button className="dashBtn" onClick={() => setMedicalOpenFor(medicalOpenFor === a.id ? null : a.id)}>Medical</button>
      <button className="dashBtn" onClick={() => setQrOpenFor(qrOpenFor === a.id ? null : a.id)}>QR Code</button>
      {a.status === 'archived' ? (
        <button className="dashBtn dashBtnPrimary" onClick={() => handleRestore(a)}>Restore</button>
      ) : (
        <button className="dashBtn" onClick={() => handleArchive(a)}>Archive</button>
      )}
      <TypeToConfirmButton
        warningLabel={`Delete ${a.name}? This cannot be undone.`}
        onConfirm={() => handleDelete(a)}
      >
        Delete
      </TypeToConfirmButton>
    </>
  );

  const renderExpanded = (a) => (
    <>
      {photosOpenFor === a.id && <PhotoManager animalId={a.id} onChanged={refresh} />}
      {medicalOpenFor === a.id && <MedicalManager animalId={a.id} onChanged={refresh} />}
      {qrOpenFor === a.id && <QrCodeViewer animalId={a.id} />}
    </>
  );

  const hasExpanded = (a) => photosOpenFor === a.id || medicalOpenFor === a.id || qrOpenFor === a.id;

  return (
    <div className="aa-module">
      <IntakesAdmin onConverted={refresh} />

      <div className="aa-header" style={{ marginTop: 24 }}>
        <div>
          <span className="aa-eyebrow">Admin · Animal Management</span>
          <h2>Manage Animal Listings</h2>
          <p>Create, edit, and retire adoption profiles for Aspins currently in shelter care.</p>
        </div>
        <button
          className="dashBtn dashBtnPrimary"
          onClick={() => { setShowCreate((v) => !v); setEditingAnimal(null); }}
        >
          {showCreate ? 'Close' : '+ Add Animal'}
        </button>
      </div>

      {error && <div className="ui-error">{error}</div>}

      <div className="aa-stat-strip">
        <div className="aa-stat-card"><div className="num">{stats.total}</div><div className="label">Total listings</div></div>
        <div className="aa-stat-card"><div className="num">{stats.available}</div><div className="label">Available</div></div>
        <div className="aa-stat-card"><div className="num">{stats.adopted}</div><div className="label">Adopted</div></div>
        <div className="aa-stat-card"><div className="num">{stats.archived}</div><div className="label">Archived</div></div>
      </div>

      <div className="aa-toolbar">
        <div className="aa-toolbar-left">
          <div className="aa-search">
            <span aria-hidden="true">🔍</span>
            <input
              type="text"
              placeholder="Search name/species/breed"
              aria-label="Search animals by name, species, or breed"
              value={filters.q}
              onChange={(e) => updateFilter('q')(e.target.value)}
            />
          </div>
          <select
            className="ui-input"
            style={{ maxWidth: 160 }}
            aria-label="Filter animals by status"
            value={filters.status}
            onChange={(e) => updateFilter('status')(e.target.value)}
          >
            <option value="">All statuses</option>
            {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
          <select
            className="ui-input"
            style={{ maxWidth: 140 }}
            aria-label="Filter animals by size"
            value={filters.size}
            onChange={(e) => updateFilter('size')(e.target.value)}
          >
            <option value="">All sizes</option>
            {SIZES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>
        <div className="aa-view-toggle" role="group" aria-label="Switch view">
          <button className={viewMode === 'table' ? 'active' : ''} onClick={() => setViewMode('table')}>Table</button>
          <button className={viewMode === 'cards' ? 'active' : ''} onClick={() => setViewMode('cards')}>Cards</button>
        </div>
      </div>

      {showCreate && <AnimalForm onCancel={() => setShowCreate(false)} onSaved={refresh} />}
      {editingAnimal && (
        <AnimalForm initial={editingAnimal} onCancel={() => setEditingAnimal(null)} onSaved={refresh} />
      )}

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : animals.length === 0 ? (
        <div className="ui-empty">No animals match these filters.</div>
      ) : viewMode === 'table' ? (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>Name</th>
                <th>Age</th>
                <th>Sex</th>
                <th>Size</th>
                <th>Status</th>
                <th style={{ textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {animals.map((a) => (
                <Fragment key={a.id}>
                  <tr>
                    <td>
                      <div className="aa-row-name">
                        <div className="aa-row-photo" aria-hidden="true">
                          {a.photo ? <img src={photoSrc(a.photo)} alt="" /> : '🐕'}
                        </div>
                        <div>
                          <div className="name">{a.name}</div>
                          <div className="sub">{a.species}{a.breed ? ` • ${a.breed}` : ''}</div>
                        </div>
                      </div>
                    </td>
                    <td>{a.age ? `${a.age} yrs` : '—'}</td>
                    <td>{a.gender || '—'}</td>
                    <td>{a.size || '—'}</td>
                    <td><StatusBadge status={a.status} /></td>
                    <td><div className="aa-row-actions">{renderActions(a)}</div></td>
                  </tr>
                  {hasExpanded(a) && (
                    <tr>
                      <td colSpan={6} className="dashExpandPanel">{renderExpanded(a)}</td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="dashAnimalGrid">
          {animals.map((a) => (
            <div className="dashAnimalCard" key={a.id}>
              <div className="dashAnimalPhoto">
                {a.photo ? <img src={photoSrc(a.photo)} alt={a.name} /> : null}
              </div>
              <div className="dashAnimalBody">
                <div className="dashAnimalName">{a.name}</div>
                <div className="dashAnimalMeta">
                  {a.species} {a.breed ? `• ${a.breed}` : ''} {a.age ? `• ${a.age} yrs` : ''}
                </div>
                <div>
                  <StatusBadge status={a.status} />
                </div>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8 }}>
                  {renderActions(a)}
                </div>
                {renderExpanded(a)}
              </div>
            </div>
          ))}
        </div>
      )}

      {!loading && animals.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </div>
  );
}
