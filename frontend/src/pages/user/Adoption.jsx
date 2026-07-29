import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { browseAnimals } from '../../lib/animalsApi';
import { Sparkles, Dog } from 'lucide-react';
import Reveal from '../../components/Reveal';

const styles = `
  .adoptGrid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
  /* Portrait tile, matching .lp-intake-card on the landing page. A fixed aspect-ratio keeps
     every card the same height whatever the photo's own proportions, and the caption sits
     on a scrim over the image instead of in a panel beneath it. */
  .adoptCard {
    position: relative; overflow: hidden; cursor: pointer; text-align: left;
    aspect-ratio: 3/4.1; border-radius: 20px; border: 1px solid var(--line);
    background: var(--bg-soft-2);
    transition: transform .3s var(--ease-out), box-shadow .3s var(--ease-out);
  }
  .adoptCard:hover { transform: translateY(-8px); box-shadow: 0 34px 60px -38px rgba(43,36,32,0.7); }
  .adoptPhoto { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: var(--brand); }
  .adoptPhoto img { width: 100%; height: 100%; object-fit: cover; transition: transform .6s var(--ease-out); }
  .adoptCard:hover .adoptPhoto img { transform: scale(1.07); }
  /* Scrim over the lower half only — enough contrast for the caption without dulling the
     whole photograph. */
  .adoptScrim { position: absolute; inset: 0; background: linear-gradient(to top, rgba(28,22,18,.88) 0%, rgba(28,22,18,.35) 38%, transparent 62%); }
  .adoptStatus {
    position: absolute; top: 14px; left: 14px; z-index: 3;
    background: rgba(255,252,246,.94); color: var(--brand-2);
    font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
    padding: 5px 12px; border-radius: 999px;
  }

  /* Glassmorphism story panel over the photo. The fill stays translucent on purpose —
     backdrop-filter has nothing to frost behind an opaque background. pointer-events:none
     keeps the click passing through to the card, which navigates to the dog's page. */
  .adoptStory {
    position: absolute; inset: 0; z-index: 1; display: flex; flex-direction: column;
    justify-content: center; gap: 8px; padding: 20px 18px 104px;
    color: #fff; text-align: left;
    background: rgba(43, 36, 32, 0.44);
    -webkit-backdrop-filter: blur(10px) saturate(140%);
    backdrop-filter: blur(10px) saturate(140%);
    border-bottom: 1px solid rgba(255, 255, 255, 0.18);
    opacity: 0; translate: 0 10px; pointer-events: none;
    transition: opacity .35s var(--ease), translate .35s var(--ease);
  }
  .adoptStoryLabel { font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: rgba(255,255,255,0.75); }
  /* Bottom padding clears the name/meta block and the apply button below it. */
  .adoptStory p { font-size: 13px; line-height: 1.5; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 5; overflow: hidden; }

  /* Caption over the scrim. z-index keeps it above the frosted panel so the dog's name
     stays legible through the whole transition. */
  /* The overlay is bottom-anchored and parks 60px low so the apply button (48px + 12px
     margin) sits below the card edge, clipped by the card's overflow. Hovering slides the
     whole block up, bringing the button into view and lifting the name exactly as the old
     height animation did — but via translate alone, so it stays on the GPU.
     (No backticks in this block: it lives inside a JS template literal.) */
  .adoptOverlay {
    position: absolute; left: 18px; right: 18px; bottom: 16px; z-index: 2; color: #fff;
    translate: 0 60px;
    transition: translate .3s var(--ease-out);
  }
  .adoptCard:hover .adoptOverlay, .adoptCard:focus-within .adoptOverlay { translate: 0 0; }
  .adoptName { font-family: 'Fraunces', serif; font-size: 1.3rem; font-weight: 600; line-height: 1.15; color: #fff; }
  .adoptMeta { font-size: 0.8rem; opacity: .82; display: flex; gap: 0.6rem; flex-wrap: wrap; margin-top: 2px; }

  /* Only opacity animates here — the reveal itself is the overlay's translate above.
     Previously this transitioned height and margin-top, which forced a layout pass per
     frame across the whole grid on every hover. */
  .adoptApply {
    width: 100%; margin-top: 12px;
    opacity: 0; pointer-events: none;
    transition: opacity .25s var(--ease-out);
  }
  .adoptCard:hover .adoptApply,
  .adoptCard:focus-within .adoptApply { opacity: 1; pointer-events: auto; }
  /* Gated behind hover:hover so phones, which fire a sticky emulated hover on tap, don't
     flash the panel mid-navigation. :focus-within covers keyboard users, since the card is
     role="button" tabIndex={0} and hover-only content would otherwise be unreachable. */
  @media (hover: hover) { .adoptCard:hover .adoptStory { opacity: 1; translate: 0 0; } }
  .adoptCard:focus-within .adoptStory { opacity: 1; translate: 0 0; }
  .adoptControls { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 2.2rem; }
  .adoptSearch { flex: 1 1 280px; display: flex; gap: 0.6rem; align-items: center; }
  @media (max-width: 900px) { .adoptGrid { grid-template-columns: 1fr 1fr; } }
  /* The old fixed .adoptPhoto height is gone — the photo now fills the card, whose height
     comes from its aspect-ratio, so it scales with the column width automatically. */
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
            {/* Each card owns its observer, so it animates at the moment it scrolls into
                view. A single group observer would not work here: the grid is far taller
                than the viewport, so its 15% threshold trips while most cards are still
                well below the fold and they would finish animating unseen. Scrolling
                itself provides the stagger, so no --i is needed. */}
            <div className="adoptGrid">
              {animals.map((a) => (
                <Reveal
                  key={a.id}
                  variant="left"
                  className="ui-card adoptCard"
                  role="button"
                  tabIndex={0}
                  onClick={() => navigate(`/adopt/${a.id}`)}
                  onKeyDown={(e) => { if (e.key === 'Enter') navigate(`/adopt/${a.id}`); }}
                >
                  {/* Portrait tile matching the landing page's featured cards: the photo
                      fills the whole card and the caption sits on a scrim over it, rather
                      than a fixed-height photo with a text panel beneath. */}
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
                  <div className="adoptScrim" aria-hidden="true" />
                  {/* status_label is the friendly wording shared with the landing page
                      (Animal::statusLabel); fall back to the raw enum if an older API
                      response doesn't carry it. tagVariant still keys off the raw value. */}
                  <span className={`adoptStatus ${tagVariant(a?.status)}`}>{a?.status_label || a?.status || 'N/A'}</span>

                  {/* Frosted panel revealed on hover/focus. Omitted when there is no story —
                      an empty frosted panel would look broken. */}
                  {a?.story && (
                    <div className="adoptStory">
                      <span className="adoptStoryLabel">Their story</span>
                      <p>{a.story}</p>
                    </div>
                  )}

                  <div className="adoptOverlay">
                    <div className="adoptName">{a?.name || 'Unnamed'}</div>
                    <div className="adoptMeta">
                      <span>{a?.species || 'Unknown species'}</span>
                      <span>{a?.age ? `${a.age} yrs` : 'N/A'}</span>
                    </div>
                    {/* Hidden until hover/focus. stopPropagation stays: the card itself is
                        also clickable, and without it the card's handler would fire too. */}
                    <button
                      className="ui-btn-primary adoptApply"
                      onClick={(e) => {
                        e.stopPropagation();
                        navigate(`/adopt/${a.id}`);
                      }}
                    >
                      View &amp; Apply to Adopt
                    </button>
                  </div>
                </Reveal>
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
