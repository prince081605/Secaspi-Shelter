import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { browseAnimals } from '../../lib/animalsApi';
import { Sparkles, Dog } from 'lucide-react';

const styles = `
  .adoptGrid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
  .adoptCard { overflow: hidden; cursor: pointer; transition: transform .2s, box-shadow .2s; }
  .adoptCard:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
  .adoptPhoto { height: 210px; background: var(--bg-soft-2); display: flex; align-items: center; justify-content: center; font-size: 5rem; }
  .adoptPhoto img { width: 100%; height: 100%; object-fit: cover; }
  .adoptBody { padding: 1.2rem 1.3rem 1.4rem; }
  .adoptName { font-size: 1.2rem; font-weight: 700; color: var(--ink); margin-bottom: 0.3rem; }
  .adoptMeta { font-size: 0.85rem; color: var(--muted); display: flex; gap: 0.7rem; flex-wrap: wrap; margin-bottom: 0.9rem; }
  .adoptControls { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 2.2rem; }
  .adoptSearch { flex: 1 1 280px; display: flex; gap: 0.6rem; align-items: center; }
  @media (max-width: 900px) { .adoptGrid { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 560px) { .adoptGrid { grid-template-columns: 1fr; } }
`;

function tagVariant(status) {
  const s = String(status || '').toLowerCase();
  if (s.includes('available')) return 'ui-tag-brand';
  if (s.includes('urgent') || s.includes('medical')) return 'ui-tag-amber';
  if (s.includes('new') || s.includes('foster')) return 'ui-tag-sky';
  return 'ui-tag-muted';
}

const STATUS_OPTIONS = ['available', 'fostered', 'medical', 'quarantine'];
const GENDER_OPTIONS = ['male', 'female'];
const SIZE_OPTIONS = ['small', 'medium', 'large'];

export default function Adoption() {
  const navigate = useNavigate();
  const [animals, setAnimals] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [genderFilter, setGenderFilter] = useState('all');
  const [sizeFilter, setSizeFilter] = useState('all');

  const updateFilter = (setter) => (value) => {
    setPage(1);
    setter(value);
  };

  useEffect(() => {
    let mounted = true;
    const timer = setTimeout(async () => {
      try {
        setLoading(true);
        setError('');
        const data = await browseAnimals({
          q: query.trim(),
          status: statusFilter,
          gender: genderFilter,
          size: sizeFilter,
          page,
        });
        if (!mounted) return;
        setAnimals(Array.isArray(data?.data) ? data.data : []);
        setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
      } catch (e) {
        if (!mounted) return;
        setError(e?.message || 'Failed to load dogs');
        setAnimals([]);
      } finally {
        if (mounted) setLoading(false);
      }
    }, 300);

    return () => {
      mounted = false;
      clearTimeout(timer);
    };
  }, [query, statusFilter, genderFilter, sizeFilter, page]);

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <nav className="ui-nav">
        <div className="ui-logo">SECASPI <span>Shelter</span></div>
        <button className="ui-btn-secondary" onClick={() => navigate('/')}>← Back to Home</button>
      </nav>

      <div className="ui-container" style={{ padding: '3rem 6vw' }}>
        <p className="ui-eyebrow" style={{ marginBottom: '1rem' }}>Available for Adoption</p>
        <h1 className="ui-h1" style={{ marginBottom: '0.6rem' }}>Meet your new best friend</h1>
        <p className="ui-muted" style={{ maxWidth: 640, marginBottom: '1.2rem' }}>
          Browse dogs in our database and start an adoption or foster request.
        </p>
        <button className="ui-btn-primary" style={{ marginBottom: '2rem' }} onClick={() => navigate('/matchmaker')}>
          <Sparkles size={16} style={{ verticalAlign: '-3px' }} /> Not sure who fits? Find your match
        </button>

        <div className="adoptControls">
          <div className="adoptSearch">
            <input
              className="ui-input"
              value={query}
              onChange={(e) => updateFilter(setQuery)(e.target.value)}
              placeholder="Search by name, species, or breed"
            />
          </div>

          <select className="ui-select" style={{ width: 'auto' }} value={statusFilter} onChange={(e) => updateFilter(setStatusFilter)(e.target.value)}>
            <option value="all">All statuses</option>
            {STATUS_OPTIONS.map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>

          <select className="ui-select" style={{ width: 'auto' }} value={genderFilter} onChange={(e) => updateFilter(setGenderFilter)(e.target.value)}>
            <option value="all">All genders</option>
            {GENDER_OPTIONS.map((g) => (
              <option key={g} value={g}>{g}</option>
            ))}
          </select>

          <select className="ui-select" style={{ width: 'auto' }} value={sizeFilter} onChange={(e) => updateFilter(setSizeFilter)(e.target.value)}>
            <option value="all">All sizes</option>
            {SIZE_OPTIONS.map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>
        </div>

        {loading ? (
          <div className="ui-empty">Loading…</div>
        ) : error ? (
          <div className="ui-empty">{error}</div>
        ) : animals.length === 0 ? (
          <div className="ui-empty">No dogs found. Try adjusting your filters.</div>
        ) : (
          <>
            <div className="adoptGrid">
              {animals.map((a) => (
                <div
                  key={a.id}
                  className="ui-card adoptCard"
                  role="button"
                  tabIndex={0}
                  onClick={() => navigate(`/adopt/${a.id}`)}
                  onKeyDown={(e) => { if (e.key === 'Enter') navigate(`/adopt/${a.id}`); }}
                >
                  <div className="adoptPhoto">
                    {a?.photo ? (
                      <img
                        src={
                          a.photo.startsWith('http')
                            ? a.photo
                            : `${import.meta.env.VITE_API_BASE_URL}/storage/${a.photo}`
                        }
                        alt={a.name || 'dog'}
                      />
                    ) : (
                      <Dog size={40} />
                    )}
                  </div>

                  <div className="adoptBody">
                    <div className="adoptName">{a?.name || 'Unnamed'}</div>
                    <div className="adoptMeta">
                      <span>{a?.species || 'Unknown species'}</span>
                      <span>{a?.age ? `${a.age} yrs` : 'N/A'}</span>
                    </div>
                    <span className={`ui-tag ${tagVariant(a?.status)}`}>{a?.status || 'N/A'}</span>
                    <button
                      className="ui-btn-secondary"
                      style={{ width: '100%', marginTop: '1rem' }}
                      onClick={(e) => {
                        e.stopPropagation();
                        navigate(`/adopt/${a.id}`);
                      }}
                    >
                      View & Apply to Adopt
                    </button>
                  </div>
                </div>
              ))}
            </div>

            {meta.last_page > 1 && (
              <div className="adoptControls" style={{ justifyContent: 'center', marginTop: '1.5rem' }}>
                <button
                  className="ui-btn-secondary"
                  disabled={meta.current_page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  ← Prev
                </button>
                <span className="ui-muted">Page {meta.current_page} of {meta.last_page}</span>
                <button
                  className="ui-btn-secondary"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                >
                  Next →
                </button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
