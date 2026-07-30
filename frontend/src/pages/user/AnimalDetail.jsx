import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { getAnimal } from '../../lib/animalsApi';
import { Dog, QrCode, Image as ImageIcon } from 'lucide-react';
import Reveal from '../../components/Reveal';
import SiteNav from '../../components/SiteNav';

const styles = `
  /* ---- Split screen ----
     Photo on the left, scrolling bento on the right. The photo column is sticky rather than
     simply tall, so it stays with you as the detail scrolls; --det-nav-h is measured from
     the sticky .ui-nav at runtime, because pinning at top:0 would slide the photo underneath
     it (the nav is sticky, z-index 50, and its height changes across breakpoints). */
  .detSplit { display: grid; grid-template-columns: 1fr 1fr; align-items: start; }

  /* No overflow:hidden here — the faces clip themselves (see .detFace), and clipping the element
     that carries the perspective is the classic way to collapse a 3D context. No background
     either: the card is inset now, so the column is just the space it floats in. */
  .detPhotoCol {
    position: sticky;
    top: var(--det-nav-h, 0px);
    height: calc(100vh - var(--det-nav-h, 0px));
    perspective: 1600px;
  }

  /* ---- The flip ----
     The photo is the front of a card and the QR code is its back: tapping the photo turns it
     over. The QR used to be a tile in the bento, but it is a utility (point a phone at it to
     open this page) rather than something you read alongside the facts, so it belongs behind
     the photo instead of taking the centre cell of the grid.

     Both faces are absolutely stacked and the wrapper rotates, so nothing reflows mid-turn.
     backface-visibility hides whichever face is turned away; the inert attribute on that face
     (set in the JSX) is what actually takes it out of the tab order and the accessibility tree,
     since a hidden backface still receives focus and clicks on its own. */
  /* The inset lives here, on the card, rather than as padding on the column: an absolutely
     positioned element resolves inset against its ancestor's padding box, which is the same as
     the border box when there is no border, so column padding would have grown the column and
     left the card full-bleed. The bottom inset is the deepest because the fixed action bar hangs
     over that edge, and corners tucked behind it would not read as rounded. */
  .detFlip { position: absolute; inset: 2rem 2rem 5rem; transform-style: preserve-3d; transition: transform 0.75s var(--ease-out); }
  .detFlip.is-flipped { transform: rotateY(180deg); }
  /* Each face carries the card's own shape: the rounding, the clip that keeps the photo and its
     caption inside that rounding, the soft shadow that lifts it off the page, and the placeholder
     tone that used to sit on the column (it has to travel with the face now that the column is
     transparent, or an animal with no photo would show its icon on bare background). */
  .detFace {
    position: absolute; inset: 0;
    backface-visibility: hidden; -webkit-backface-visibility: hidden;
    border-radius: 24px; overflow: hidden;
    background: var(--bg-soft-2);
    box-shadow: var(--shadow-md);
  }
  .detFaceBack { transform: rotateY(180deg); }
  /* Belt and braces for browsers without inert: the turned-away face stops taking clicks. */
  .detFlip.is-flipped .detFaceFront,
  .detFlip:not(.is-flipped) .detFaceBack { pointer-events: none; }

  /* The tap target for the flip. Its own layer rather than a click handler on the column, so it
     sits above the scrim but below the thumbnails — which stay clickable, and stay out of this
     element rather than nested inside a role="button". */
  .detFlipSurface { position: absolute; inset: 0; z-index: 2; cursor: pointer; border: 0; background: transparent; padding: 0; }
  .detFlipSurface:focus-visible { outline: 3px solid #fff; outline-offset: -6px; }

  .detFlipHint {
    position: absolute; right: 2.5rem; top: 1.5rem; z-index: 4; display: flex; align-items: center; gap: 0.4rem;
    background: rgba(255,252,246,.94); color: var(--brand-2); border-radius: 999px; padding: 7px 13px;
    font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
    box-shadow: var(--shadow-sm); pointer-events: none;
  }
  .detFlipSurface:hover ~ .detFlipHint { background: #fff; }

  /* ---- The back: the QR code ---- */
  /* overflow:auto is a safety net, not the plan: the sizes below are set so the whole face fits
     a 720p viewport without scrolling, but a short window or a long name should scroll rather
     than put the Download button out of reach. */
  .detQrFace {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.75rem;
    padding: 1.75rem; text-align: center; overflow: auto;
    background: linear-gradient(165deg, var(--brand-soft) 0%, var(--bg-soft-2) 100%);
  }
  .detQrCard { background: #fff; border-radius: 20px; padding: 1.1rem; box-shadow: var(--shadow-md); line-height: 0; }
  .detQrCard img { display: block; width: min(180px, 32vw); height: auto; }
  .detQrName { font-family: 'Fraunces', serif; font-size: 1.4rem; font-weight: 600; color: var(--ink); margin: 0; }
  .detQrCopy { font-size: 0.88rem; color: var(--ink-soft); max-width: 30ch; margin: 0; }
  /* Lifted above the flip surface, which otherwise covers the whole face — this is the one
     thing on the back that should do something other than turn the card over. */
  .detQrActions { position: relative; z-index: 3; display: flex; gap: 0.6rem; flex-wrap: wrap; justify-content: center; }
  /* The front's focus ring is white, for a dark photo; this face is light. */
  .detQrFace .detFlipSurface:focus-visible { outline-color: var(--brand-2); }

  .detPhotoMedia { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: var(--brand); }
  .detPhotoMedia img { width: 100%; height: 100%; object-fit: cover; }
  /* Both purely decorative, and both cover the photo — without this they would swallow the taps
     meant for the flip surface underneath them. */
  .detPhotoScrim { position: absolute; inset: 0; pointer-events: none; background: linear-gradient(to top, rgba(28,22,18,.92) 0%, rgba(28,22,18,.4) 40%, rgba(28,22,18,.04) 70%); }
  .detPhotoCaption { position: absolute; left: 0; right: 0; bottom: 0; z-index: 3; pointer-events: none; padding: 0 2.5rem 2rem; color: #fff; }
  .detStatus {
    display: inline-block; background: rgba(255,252,246,.94); color: var(--brand-2);
    font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
    padding: 5px 12px; border-radius: 999px; margin-bottom: 0.7rem;
  }
  .detName { font-family: 'Fraunces', serif; font-weight: 600; line-height: 1.05; letter-spacing: -0.02em; color: #fff; font-size: clamp(2rem, 1rem + 2.6vw, 3.4rem); margin: 0; }
  .detMeta { display: flex; gap: 0.9rem; flex-wrap: wrap; color: rgba(255,255,255,.85); font-size: 0.9rem; margin-top: 0.5rem; text-transform: capitalize; }

  /* Thumbnails: 44px minimum, they are the only way to change the photo. */
  .detThumbs { position: absolute; left: 2.5rem; top: 1.5rem; z-index: 5; display: flex; gap: 0.5rem; flex-wrap: wrap; max-width: 60%; }
  .detThumb { width: 52px; height: 52px; min-width: 44px; min-height: 44px; border-radius: 10px; overflow: hidden; cursor: pointer; border: 2px solid rgba(255,255,255,.55); }
  .detThumb.active { border-color: #fff; }
  .detThumb:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
  .detThumb img { width: 100%; height: 100%; object-fit: cover; }

  /* ---- Bento ----
     Three columns. The middle row is Facts (one column) and Medical (two) — both always render,
     Medical with an empty state, so the row is never left with a hole. Everything optional
     spans all three. The QR code used to hold the centre cell; it now lives on the back of the
     photo, which is why Medical takes the width it left behind. */
  /* minmax(0, 1fr), not 1fr. A bare 1fr is minmax(auto, 1fr), whose auto minimum lets a
     tile's content force its track wider than its share, so the columns resolve to unequal
     widths (they measured 172/122/226px) instead of equal thirds. */
  .detBento { padding: 2.5rem 2.5rem 8rem; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; align-content: start; }
  .bentoTile { background: var(--paper); border: 1px solid var(--line); border-radius: 18px; padding: 1.3rem 1.4rem; }
  .bentoWide { grid-column: 1 / -1; }
  .bentoTitle { font-family: 'Fraunces', serif; font-size: 1.05rem; font-weight: 600; color: var(--ink); margin-bottom: 0.8rem; }
  .bentoLabel { font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); margin-bottom: 0.5rem; }

  .bentoFacts dl { display: grid; grid-template-columns: 1fr; gap: 0.55rem; margin: 0; }
  .bentoFacts div { display: flex; justify-content: space-between; gap: 0.6rem; font-size: 0.85rem; }
  .bentoFacts dt { color: var(--muted); text-transform: capitalize; }
  .bentoFacts dd { margin: 0; font-weight: 600; color: var(--ink); text-transform: capitalize; text-align: right; }

  .bentoMedical { grid-column: span 2; }

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
       a sticky element has nothing to stick against in a single column. The action bar no longer
       overlaps the card here, so the deep bottom inset goes away and the sides line up with the
       bento's 6vw gutter. */
    .detSplit { grid-template-columns: 1fr; }
    .detPhotoCol { position: static; height: clamp(300px, 46vh, 440px); }
    .detFlip { inset: 1rem 6vw; }
    .detFace { border-radius: 18px; }
    .detBento { padding: 2rem 6vw 9rem; }

    /* The back now has a banner's worth of height instead of a full column, so it drops what
       the front already says — the name and the "Scan" eyebrow — and shrinks the code to fit. */
    .detQrFace { padding: 1.25rem; gap: 0.7rem; }
    .detQrFace .bentoLabel, .detQrName { display: none; }
    .detQrCard { padding: 0.8rem; border-radius: 16px; }
    .detQrCard img { width: min(170px, 38vw); }
    .detQrCopy { font-size: 0.82rem; }
  }
  @media (max-width: 720px) {
    .detBento { grid-template-columns: repeat(2, 1fr); }
    /* Two columns: Medical drops its extra span and sits beside Facts. */
    .bentoMedical { grid-column: auto; }
    .detFlipHint { right: 5vw; top: 1rem; }
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

  @media (prefers-reduced-motion: reduce) {
    /* Still turns over — it just cuts rather than sweeping through the rotation. */
    .detFlip { transition: none; }
  }
`;

export default function AnimalDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [animal, setAnimal] = useState(null);
  const [activePhoto, setActivePhoto] = useState(0);
  const [flipped, setFlipped] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [navHeight, setNavHeight] = useState(0);

  const flipSurfaceRef = useRef(null);
  const qrCloseRef = useRef(null);
  const hasFlippedRef = useRef(false);

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
        setFlipped(false);
      } catch (e) {
        if (!mounted) return;
        setError(e?.message || 'Failed to load this animal');
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => { mounted = false; };
  }, [id]);

  /* Focus follows the turn. Whichever face is away is inert, which blurs anything focused
     inside it — so clicking the photo would otherwise drop focus on the body, leaving the
     revealed QR panel unreachable by keyboard and Escape with nothing listening. Turning back
     returns focus to the photo, but only once the card has actually been flipped, so a fresh
     page load doesn't steal focus from the top of the document. */
  useEffect(() => {
    if (flipped) {
      hasFlippedRef.current = true;
      qrCloseRef.current?.focus();
    } else if (hasFlippedRef.current) {
      flipSurfaceRef.current?.focus();
    }
  }, [flipped]);

  /* Escape turns the card back over from anywhere on the page, as any dismissible overlay does.
     Bound to the document rather than the column so it works no matter where focus has drifted
     (the Download link, the action bar, a nav item). */
  useEffect(() => {
    if (!flipped) return undefined;
    const onKeyDown = (e) => {
      if (e.key === 'Escape') setFlipped(false);
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [flipped]);

  /* The photo column sticks below the sticky .ui-nav, whose height differs per breakpoint,
     so it is measured rather than hardcoded.
     Deliberately not ResizeObserver-only: environments that throttle or never deliver
     observer callbacks would leave this at 0 and slide the photo under the nav. A deferred
     direct measurement plus a resize listener always runs; the observer is an optional
     refinement that also catches nav height changes which don't involve a window resize.
     The measurement is deferred so it isn't a synchronous setState inside an effect. */
  useEffect(() => {
    const measure = () => {
      const nav = document.querySelector('.site-header');
      if (nav) setNavHeight(nav.getBoundingClientRect().height);
    };
    const initial = setTimeout(measure, 0);
    window.addEventListener('resize', measure, { passive: true });

    let ro;
    const nav = document.querySelector('.site-header');
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

      <SiteNav />

      {loading ? (
        <div className="ui-empty" style={{ margin: '3rem 6vw' }}>Loading…</div>
      ) : error || !animal ? (
        <div className="ui-empty" style={{ margin: '3rem 6vw' }}>{error || 'Animal not found.'}</div>
      ) : (
        <>
          <div className="detSplit" style={{ '--det-nav-h': `${navHeight}px` }}>
            {/* Left: the dog, with the QR code on the back of the photo. */}
            <div className="detPhotoCol">
              <div className={`detFlip${flipped ? ' is-flipped' : ''}`}>
                <div className="detFace detFaceFront" inert={flipped}>
                  <div className="detPhotoMedia">
                    {animal.photos?.length ? (
                      <img src={photoUrl(animal.photos[activePhoto])} alt={animal.name} />
                    ) : (
                      <Dog size={96} />
                    )}
                  </div>
                  <div className="detPhotoScrim" aria-hidden="true" />

                  {/* Only offered when there is actually a code to turn over to. */}
                  {animal.qr_code && (
                    <>
                      <button
                        ref={flipSurfaceRef}
                        type="button"
                        className="detFlipSurface"
                        aria-label={`Show the QR code for ${animal.name || 'this animal'}`}
                        onClick={() => setFlipped(true)}
                      />
                      <div className="detFlipHint">
                        <QrCode size={14} aria-hidden="true" />
                        Tap to scan
                      </div>
                    </>
                  )}

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

                {animal.qr_code && (
                  <div className="detFace detFaceBack detQrFace" inert={!flipped}>
                    {/* The turn is symmetric: a tap anywhere brings the photo back, the same way
                        a tap on the photo brought the code up. The surface covers the code and
                        its caption, so only Download — lifted above it — is its own target. */}
                    <button
                      ref={qrCloseRef}
                      type="button"
                      className="detFlipSurface"
                      aria-label={`Show the photo of ${animal.name || 'this animal'} again`}
                      onClick={() => setFlipped(false)}
                    />
                    <div className="detFlipHint">
                      <ImageIcon size={14} aria-hidden="true" />
                      Tap for photo
                    </div>

                    <div className="bentoLabel">Scan</div>
                    <div className="detQrCard">
                      <img src={photoUrl(animal.qr_code)} alt={`QR code for ${animal.name}`} />
                    </div>
                    <h2 className="detQrName">{animal.name || 'Unnamed'}</h2>
                    <p className="detQrCopy">
                      Point a phone camera at this code to open {animal.name || 'this animal'}'s page.
                    </p>
                    <div className="detQrActions">
                      <a
                        className="ui-btn-primary"
                        href={photoUrl(animal.qr_code)}
                        download={`${animal.name || 'animal'}-qr.svg`}
                      >
                        Download QR
                      </a>
                    </div>
                  </div>
                )}
              </div>
            </div>

            {/* Right: bento */}
            <div className="detBento">
              {animal.rescue_story && (
                <Reveal className="bentoTile bentoWide">
                  <div className="bentoLabel">Their story</div>
                  <p className="detStory">{animal.rescue_story}</p>
                </Reveal>
              )}

              {/* Middle row — Facts | Medical. Both always render, so the row is never partial. */}
              <Reveal className="bentoTile bentoFacts">
                <div className="bentoLabel">At a glance</div>
                <dl>
                  {facts.map(([k, v]) => (
                    <div key={k}><dt>{k}</dt><dd>{v}</dd></div>
                  ))}
                </dl>
              </Reveal>

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
