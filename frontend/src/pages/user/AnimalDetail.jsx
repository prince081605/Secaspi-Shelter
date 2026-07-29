import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { getAnimal } from '../../lib/animalsApi';
import { Dog } from 'lucide-react';
import Reveal from '../../components/Reveal';

const styles = `
  /* ---- Split screen ----
     Photo on the left, scrolling bento on the right. The photo column is sticky rather than
     simply tall, so it stays with you as the detail scrolls; --det-nav-h is measured from
     the sticky .ui-nav at runtime, because pinning at top:0 would slide the photo underneath
     it (the nav is sticky, z-index 50, and its height changes across breakpoints). */
  .detSplit { display: grid; grid-template-columns: 1fr 1fr; align-items: start; }

  .detPhotoCol {
    position: sticky;
    top: var(--det-nav-h, 0px);
    height: calc(100vh - var(--det-nav-h, 0px));
    overflow: hidden;
    background: var(--bg-soft-2);
  }
  .detPhotoMedia { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: var(--brand); }
  .detPhotoMedia img { width: 100%; height: 100%; object-fit: cover; }
  .detPhotoScrim { position: absolute; inset: 0; background: linear-gradient(to top, rgba(28,22,18,.92) 0%, rgba(28,22,18,.4) 40%, rgba(28,22,18,.04) 70%); }
  .detPhotoCaption { position: absolute; left: 0; right: 0; bottom: 0; z-index: 2; padding: 0 2.5rem 2rem; color: #fff; }
  .detStatus {
    display: inline-block; background: rgba(255,252,246,.94); color: var(--brand-2);
    font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
    padding: 5px 12px; border-radius: 999px; margin-bottom: 0.7rem;
  }
  .detName { font-family: 'Fraunces', serif; font-weight: 600; line-height: 1.05; letter-spacing: -0.02em; color: #fff; font-size: clamp(2rem, 1rem + 2.6vw, 3.4rem); margin: 0; }
  .detMeta { display: flex; gap: 0.9rem; flex-wrap: wrap; color: rgba(255,255,255,.85); font-size: 0.9rem; margin-top: 0.5rem; text-transform: capitalize; }

  /* Thumbnails: 44px minimum, they are the only way to change the photo. */
  .detThumbs { position: absolute; left: 2.5rem; top: 1.5rem; z-index: 3; display: flex; gap: 0.5rem; flex-wrap: wrap; max-width: 70%; }
  .detThumb { width: 52px; height: 52px; min-width: 44px; min-height: 44px; border-radius: 10px; overflow: hidden; cursor: pointer; border: 2px solid rgba(255,255,255,.55); }
  .detThumb.active { border-color: #fff; }
  .detThumb:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
  .detThumb img { width: 100%; height: 100%; object-fit: cover; }

  /* ---- Bento ----
     Three columns. The QR tile is placed explicitly in column 2 of the middle row, flanked
     by Facts and Medical — both of which always render (Medical has an empty state), so the
     centre cell can never be orphaned by missing data. Everything optional spans all three. */
  /* minmax(0, 1fr), not 1fr. A bare 1fr is minmax(auto, 1fr), whose auto minimum lets a
     tile's content force its track wider than its share — the three columns resolved to
     172/122/226px instead of equal thirds, throwing the QR tile off centre. */
  .detBento { padding: 2.5rem 2.5rem 8rem; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; align-content: start; }
  .bentoTile { background: var(--paper); border: 1px solid var(--line); border-radius: 18px; padding: 1.3rem 1.4rem; }
  .bentoWide { grid-column: 1 / -1; }
  .bentoTitle { font-family: 'Fraunces', serif; font-size: 1.05rem; font-weight: 600; color: var(--ink); margin-bottom: 0.8rem; }
  .bentoLabel { font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); margin-bottom: 0.5rem; }

  .bentoFacts dl { display: grid; grid-template-columns: 1fr; gap: 0.55rem; margin: 0; }
  .bentoFacts div { display: flex; justify-content: space-between; gap: 0.6rem; font-size: 0.85rem; }
  .bentoFacts dt { color: var(--muted); text-transform: capitalize; }
  .bentoFacts dd { margin: 0; font-weight: 600; color: var(--ink); text-transform: capitalize; text-align: right; }

  /* The centre tile. Accent background so it reads as the anchor of the grid. */
  .bentoQr { grid-column: 2; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.6rem; background: var(--brand-soft); border-color: var(--brand-light, var(--line)); text-align: center; }
  .bentoQr img { width: 100%; max-width: 140px; height: auto; background: #fff; border-radius: 10px; padding: 6px; }
  .bentoQr a { font-size: 0.8rem; color: var(--brand-2); font-weight: 600; text-decoration: none; }
  .bentoQr a:hover { text-decoration: underline; }
  /* If an animal somehow has no QR, Facts absorbs the empty centre rather than leaving a hole. */
  .detBento.noQr .bentoFacts { grid-column: span 2; }

  .detStory { line-height: 1.75; color: var(--ink-soft); font-size: 0.98rem; }
  .detChips { display: flex; gap: 0.5rem; flex-wrap: wrap; }
  .detChip { display: inline-block; padding: 0.35rem 0.8rem; background: var(--brand-soft); border-radius: 999px; font-size: 0.82rem; text-transform: capitalize; color: var(--brand-2); font-weight: 600; }

  .detRecord { padding: 0.7rem 0; border-bottom: 1px solid var(--line); font-size: 0.85rem; }
  .detRecord:last-child { border-bottom: none; padding-bottom: 0; }
  .detRecordType { font-weight: 700; text-transform: capitalize; color: var(--brand); margin-right: 0.4rem; }
  .detRecordDate { color: var(--muted); font-size: 0.78rem; margin-top: 0.2rem; }

  .detGuidesGrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 0.8rem; }
  .detGuideCard { padding: 0.9rem; background: var(--bg-soft-2); border-radius: 12px; border-left: 4px solid var(--brand); }
  .detGuideCard.behavioral { border-left-color: var(--brand-2); background: rgba(193, 97, 46, 0.06); }
  .detGuideTitle { font-weight: 700; color: var(--ink); margin-bottom: 0.4rem; font-size: 0.9rem; }
  .detGuideCategory { font-size: 0.7rem; text-transform: uppercase; color: var(--muted); letter-spacing: 0.5px; margin-bottom: 0.55rem; }
  .detGuideContent { font-size: 0.85rem; color: var(--ink-soft); line-height: 1.5; }

  /* ---- Action bar ----
     Fixed to the bottom, not sticky to the top: .ui-nav already occupies top:0 at z-index 50,
     so a top-sticky bar slides underneath it and disappears. */
  .detActionBar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 20; background: rgba(255,252,246,.92); -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px); border-top: 1px solid var(--line); box-shadow: 0 -8px 24px -18px rgba(43,36,32,.5); }
  .detActionBarInner { max-width: 1180px; margin: 0 auto; padding: 0.7rem 2.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
  .detActionBarName { font-family: 'Fraunces', serif; font-size: 1.15rem; font-weight: 600; color: var(--ink); }
  .detActions { display: flex; gap: 0.7rem; flex-wrap: wrap; }

  @media (max-width: 1024px) {
    /* Stack: the photo becomes a banner and the bento runs full width. Sticky is dropped —
       a sticky element has nothing to stick against in a single column. */
    .detSplit { grid-template-columns: 1fr; }
    .detPhotoCol { position: static; height: clamp(300px, 46vh, 440px); }
    .detBento { padding: 2rem 6vw 9rem; }
  }
  @media (max-width: 720px) {
    .detBento { grid-template-columns: repeat(2, 1fr); }
    /* Two columns can't hold a centred third cell, so the QR spans and centres itself. */
    .bentoQr { grid-column: 1 / -1; }
  }
  @media (max-width: 560px) {
    /* The bar grows to ~129px here because both CTAs stack full-width, so the desktop
       clearance is short. 11rem leaves real breathing room. */
    .detBento { grid-template-columns: 1fr; padding: 1.5rem 5vw 11rem; gap: 0.8rem; }
    .detPhotoCaption { padding: 0 5vw 1.5rem; }
    .detThumbs { left: 5vw; top: 1rem; }
    .detThumb { width: 44px; height: 44px; }
    .detActionBarInner { padding: 0.7rem 5vw; flex-direction: column; align-items: stretch; }
    .detActionBarName { display: none; }
    .detActions .ui-btn-primary, .detActions .ui-btn-secondary { flex: 1 1 auto; }
  }
`;

export default function AnimalDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [animal, setAnimal] = useState(null);
  const [activePhoto, setActivePhoto] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [navHeight, setNavHeight] = useState(0);

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

  /* The photo column sticks below the sticky .ui-nav, whose height differs per breakpoint,
     so it is measured rather than hardcoded.
     Deliberately not ResizeObserver-only: environments that throttle or never deliver
     observer callbacks would leave this at 0 and slide the photo under the nav. A deferred
     direct measurement plus a resize listener always runs; the observer is an optional
     refinement that also catches nav height changes which don't involve a window resize.
     The measurement is deferred so it isn't a synchronous setState inside an effect. */
  useEffect(() => {
    const measure = () => {
      const nav = document.querySelector('.ui-nav');
      if (nav) setNavHeight(nav.getBoundingClientRect().height);
    };
    const initial = setTimeout(measure, 0);
    window.addEventListener('resize', measure, { passive: true });

    let ro;
    const nav = document.querySelector('.ui-nav');
    if (nav && typeof ResizeObserver !== 'undefined') {
      ro = new ResizeObserver(measure);
      ro.observe(nav);
    }
    return () => {
      clearTimeout(initial);
      window.removeEventListener('resize', measure);
      if (ro) ro.disconnect();
    };
  }, [loading]);

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

  const facts = animal
    ? [
        ['Species', animal.species],
        ['Breed', animal.breed],
        ['Age', animal.age ? `${animal.age} yrs` : null],
        ['Gender', animal.gender],
        ['Size', animal.size],
        ['Weight', animal.weight ? `${animal.weight} kg` : null],
      ].filter(([, v]) => v)
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
        <>
          <div className="detSplit" style={{ '--det-nav-h': `${navHeight}px` }}>
            {/* Left: the dog */}
            <div className="detPhotoCol">
              <div className="detPhotoMedia">
                {animal.photos?.length ? (
                  <img src={photoUrl(animal.photos[activePhoto])} alt={animal.name} />
                ) : (
                  <Dog size={96} />
                )}
              </div>
              <div className="detPhotoScrim" aria-hidden="true" />

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

              <div className="detPhotoCaption">
                <span className="detStatus">{animal.status_label || animal.status}</span>
                <h1 className="detName">{animal.name || 'Unnamed'}</h1>
                <div className="detMeta">
                  <span>{animal.species}</span>
                  {animal.breed && <span>{animal.breed}</span>}
                  {animal.age && <span>{animal.age} yrs</span>}
                  {animal.gender && <span>{animal.gender}</span>}
                </div>
              </div>
            </div>

            {/* Right: bento */}
            <div className={`detBento${animal.qr_code ? '' : ' noQr'}`}>
              {animal.rescue_story && (
                <Reveal className="bentoTile bentoWide">
                  <div className="bentoLabel">Their story</div>
                  <p className="detStory">{animal.rescue_story}</p>
                </Reveal>
              )}

              {/* Middle row — Facts | QR | Medical. All three always render, so the centre
                  tile is never orphaned. */}
              <Reveal className="bentoTile bentoFacts">
                <div className="bentoLabel">At a glance</div>
                <dl>
                  {facts.map(([k, v]) => (
                    <div key={k}><dt>{k}</dt><dd>{v}</dd></div>
                  ))}
                </dl>
              </Reveal>

              {animal.qr_code && (
                <Reveal className="bentoTile bentoQr">
                  <div className="bentoLabel">Scan</div>
                  <img src={photoUrl(animal.qr_code)} alt={`QR code for ${animal.name}`} />
                  <a href={photoUrl(animal.qr_code)} download={`${animal.name || 'animal'}-qr.svg`}>Download QR</a>
                </Reveal>
              )}

              <Reveal className="bentoTile bentoMedical">
                <div className="bentoLabel">Medical history</div>
                {history.length === 0 ? (
                  <p className="ui-muted" style={{ fontSize: '0.85rem' }}>No records on file yet.</p>
                ) : (
                  history.map((h) => (
                    <div className="detRecord" key={h.key}>
                      <span className="detRecordType">{h.label}</span>
                      {h.detail && <span>{h.detail}</span>}
                      {h.date && <div className="detRecordDate">{h.date}</div>}
                    </div>
                  ))
                )}
              </Reveal>

              {animal.behavioral_assessment && animal.behavioral_assessment.length > 0 && (
                <Reveal className="bentoTile bentoWide">
                  <div className="bentoLabel">Behavioral notes</div>
                  <div className="detChips">
                    {animal.behavioral_assessment.map((issue) => (
                      <span key={issue} className="detChip">{issue}</span>
                    ))}
                  </div>
                </Reveal>
              )}

              {animal.care_guides && animal.care_guides.length > 0 && (
                <Reveal className="bentoTile bentoWide">
                  <div className="bentoLabel">
                    Care guide for {animal.species === 'dog' ? 'this dog' : animal.species === 'cat' ? 'this cat' : 'this animal'}
                  </div>
                  <div className="detGuidesGrid">
                    {animal.care_guides.map((guide) => (
                      <div key={guide.id} className={`detGuideCard${guide.is_behavioral ? ' behavioral' : ''}`}>
                        <div className="detGuideTitle">{guide.title}</div>
                        <div className="detGuideCategory">{guide.category}</div>
                        <div className="detGuideContent">{guide.content}</div>
                      </div>
                    ))}
                  </div>
                </Reveal>
              )}
            </div>
          </div>

          <div className="detActionBar">
            <div className="detActionBarInner">
              <span className="detActionBarName">{animal.name || 'Unnamed'}</span>
              <div className="detActions">
                <button className="ui-btn-primary" onClick={() => navigate(`/adopt/${id}/apply`)}>Apply to Adopt</button>
                <button className="ui-btn-secondary" onClick={() => navigate(`/adopt/${id}/foster`)}>Apply to Foster</button>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
