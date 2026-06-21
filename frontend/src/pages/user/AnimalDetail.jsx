import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { getAnimal } from '../../lib/animalsApi';

const styles = `
  .detBody { padding: 3rem 6vw; display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; }
  .detGallery { display: flex; flex-direction: column; gap: 0.8rem; }
  .detMain { height: 380px; border-radius: var(--radius); background: var(--bg-soft-2); display: flex; align-items: center; justify-content: center; font-size: 6rem; overflow: hidden; }
  .detMain img { width: 100%; height: 100%; object-fit: cover; }
  .detThumbs { display: flex; gap: 0.6rem; flex-wrap: wrap; }
  .detThumb { width: 70px; height: 70px; border-radius: 10px; overflow: hidden; cursor: pointer; border: 2px solid transparent; }
  .detThumb.active { border-color: var(--brand); }
  .detThumb:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
  .detThumb img { width: 100%; height: 100%; object-fit: cover; }
  .detMeta { display: flex; gap: 1rem; flex-wrap: wrap; color: var(--muted); font-size: 0.95rem; margin: 0.8rem 0 1rem; }
  .detStory { line-height: 1.75; color: var(--ink-soft); margin: 1.2rem 0 2rem; }
  .detActions { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 2.5rem; }
  .detRecord { padding: 0.9rem 1.1rem; margin-bottom: 0.7rem; font-size: 0.9rem; }
  .detRecordType { font-weight: 700; text-transform: capitalize; color: var(--brand); margin-right: 0.5rem; }
  .detRecordDate { color: var(--muted); font-size: 0.82rem; margin-top: 0.2rem; }
  .detQr { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; padding: 1rem; border: 1px solid var(--line); border-radius: var(--radius); background: #fff; margin-top: 0.4rem; }
  .detQr img { width: 140px; height: 140px; }
  .detQr a { font-size: 0.85rem; color: var(--brand); text-decoration: none; }
  .detQr a:hover { text-decoration: underline; }
  @media (max-width: 900px) { .detBody { grid-template-columns: 1fr; padding: 2rem 6vw; } }
`;

export default function AnimalDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [animal, setAnimal] = useState(null);
  const [activePhoto, setActivePhoto] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        setLoading(true);
        setError('');
        const data = await getAnimal(id);
        if (!mounted) return;
        setAnimal(data?.animal || null);
        setActivePhoto(0);
      } catch (e) {
        if (!mounted) return;
        setError(e?.message || 'Failed to load this animal');
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => { mounted = false; };
  }, [id]);

  const photoUrl = (p) => (p.startsWith('http') ? p : `${import.meta.env.VITE_API_BASE_URL}/storage/${p}`);

  const history = animal
    ? [
        ...(animal.medical_records || []).map((m) => ({
          key: `med-${m.id}`,
          date: m.record_date,
          label: m.type,
          detail: m.description,
        })),
        ...(animal.vaccinations || []).map((v) => ({
          key: `vac-${v.id}`,
          date: v.date_given,
          label: 'vaccination',
          detail: v.vaccine_name,
        })),
      ].sort((a, b) => new Date(b.date || 0) - new Date(a.date || 0))
    : [];

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <nav className="ui-nav">
        <div className="ui-logo">SECASPI <span>Shelter</span></div>
        <button className="ui-btn-secondary" onClick={() => navigate('/adopt')}>← Back to Dogs</button>
      </nav>

      {loading ? (
        <div className="ui-empty" style={{ margin: '3rem 6vw' }}>Loading…</div>
      ) : error || !animal ? (
        <div className="ui-empty" style={{ margin: '3rem 6vw' }}>{error || 'Animal not found.'}</div>
      ) : (
        <div className="detBody">
          <div className="detGallery">
            <div className="detMain">
              {animal.photos?.length ? (
                <img src={photoUrl(animal.photos[activePhoto])} alt={animal.name} />
              ) : (
                '🐶'
              )}
            </div>
            {animal.photos?.length > 1 && (
              <div className="detThumbs">
                {animal.photos.map((p, i) => (
                  <div
                    key={p}
                    role="button"
                    tabIndex={0}
                    aria-label={`Show photo ${i + 1} of ${animal.photos.length}`}
                    aria-pressed={i === activePhoto}
                    className={`detThumb${i === activePhoto ? ' active' : ''}`}
                    onClick={() => setActivePhoto(i)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        setActivePhoto(i);
                      }
                    }}
                  >
                    <img src={photoUrl(p)} alt={`${animal.name} ${i + 1}`} />
                  </div>
                ))}
              </div>
            )}
            {animal.qr_code && (
              <div className="detQr">
                <img src={photoUrl(animal.qr_code)} alt={`QR code for ${animal.name}`} />
                <a href={photoUrl(animal.qr_code)} download={`${animal.name || 'animal'}-qr.svg`}>Download QR</a>
              </div>
            )}
          </div>

          <div>
            <h1 className="ui-h1">{animal.name || 'Unnamed'}</h1>
            <div className="detMeta">
              <span>{animal.species}</span>
              {animal.breed && <span>{animal.breed}</span>}
              {animal.age && <span>{animal.age} yrs</span>}
              {animal.gender && <span>{animal.gender}</span>}
              {animal.size && <span>{animal.size}</span>}
              {animal.weight && <span>{animal.weight} kg</span>}
            </div>
            <span className="ui-tag ui-tag-brand">{animal.status}</span>
            {animal.rescue_story && <p className="detStory">{animal.rescue_story}</p>}

            <div className="detActions">
              <button className="ui-btn-primary" onClick={() => navigate(`/adopt/${id}/apply`)}>Apply to Adopt</button>
              <button className="ui-btn-secondary" onClick={() => navigate(`/adopt/${id}/foster`)}>Apply to Foster</button>
            </div>

            <h2 className="ui-h3" style={{ marginBottom: '0.8rem' }}>Medical History</h2>
            {history.length === 0 ? (
              <p className="ui-muted" style={{ fontSize: '0.9rem' }}>No medical records on file yet.</p>
            ) : (
              history.map((h) => (
                <div className="ui-card detRecord" key={h.key}>
                  <span className="detRecordType">{h.label}</span>
                  {h.detail && <span>{h.detail}</span>}
                  {h.date && <div className="detRecordDate">{h.date}</div>}
                </div>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
