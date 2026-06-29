import { useEffect, useRef, useState } from "react";
import { MapContainer, TileLayer, CircleMarker, useMapEvents, useMap } from "react-leaflet";
import "leaflet/dist/leaflet.css";

// Presentational sections for the public LandingPage, extracted from LandingPage.jsx (audit §0.2)
// so that file holds only data-loading/orchestration. These are pure, props-driven components.

// Default map view: Metro Manila (the shelter operates in the Philippines).
const RESCUE_MAP_DEFAULT = [14.5995, 120.9842];

// Click anywhere on the map to drop/move the rescue pin.
function ClickToPin({ onPin }) {
  useMapEvents({ click: (e) => onPin(e.latlng.lat, e.latlng.lng) });
  return null;
}

// Recenter the map when the pin moves (e.g. after "use my current location").
function RecenterOnPin({ lat, lng }) {
  const map = useMap();
  useEffect(() => {
    if (lat != null && lng != null) map.flyTo([lat, lng], 16);
  }, [lat, lng, map]);
  return null;
}

const pathways = [
  { key: "adopt", icon: "🏡", title: "Adopt", desc: "Browse profiles of vaccinated, vetted Aspins ready for a forever home.", linkLabel: "View adoptable dogs →", target: "animals" },
  { key: "donate", icon: "🐾", title: "Donate", desc: "Fund food, vaccines, and medical care for dogs currently in intake. Every peso is tracked and reported.", linkLabel: "Support the shelter →", target: "donate" },
  { key: "report", icon: "📍", title: "Report a stray", desc: "Spotted an Aspin in need? Submit a rescue report with location and photos so our team can respond quickly.", linkLabel: "File a report →", target: "report" },
];

const processSteps = [
  { title: "Intake", desc: "A stray is reported or surrendered and brought in for a health and temperament assessment." },
  { title: "Care", desc: "Vaccination, deworming, and recovery time with our volunteer-run medical team." },
  { title: "Matching", desc: "Profile goes live for adoption, matched against applicant lifestyle and home setup." },
  { title: "Homecoming", desc: "Home check, adoption agreement, and a follow-up visit within the first month." },
];

export const donationAmounts = ["₱100", "₱300", "₱500", "₱1,000", "₱2,500"];

function animalPhotoSrc(photo) {
  if (!photo) return null;
  return photo.startsWith("http") ? photo : `${import.meta.env.VITE_API_BASE_URL}/storage/${photo}`;
}

// Fires once an element scrolls into view, used to trigger the fade/slide-up reveal CSS.
function useInView() {
  const ref = useRef(null);
  const [inView, setInView] = useState(false);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setInView(true);
          observer.disconnect();
        }
      },
      { threshold: 0.15 }
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  return [ref, inView];
}

export function Navbar({ shelterName, isLoggedIn, menuOpen, scrolled, onToggleMenu, onNavigate }) {
  return (
    <header className={`lp-header${scrolled ? " scrolled" : ""}`}>
      <nav className="lp-nav" aria-label="Primary">
        <a href="/" className="lp-logo">
          <span className="lp-logo-mark" aria-hidden="true">🐾</span>
          {shelterName}
        </a>
        <ul className="lp-nav-links">
          <li><a href="/adopt">Adopt</a></li>
          <li><a href="/visit">Visit</a></li>
          <li><a href="/volunteer">Volunteer</a></li>
          <li><a href="/transparency">Transparency</a></li>
          <li><a href="#donate">Donate</a></li>
        </ul>
        <div className="lp-nav-actions">
          <button className="lp-nav-ghost" onClick={() => onNavigate(isLoggedIn ? "/dashboard" : "/login")}>
            {isLoggedIn ? "Dashboard" : "Login"}
          </button>
          <a href="#report" className="lp-nav-cta">Report a stray</a>
          <button
            className={`lp-hamburger${menuOpen ? " open" : ""}`}
            onClick={onToggleMenu}
            aria-label="Toggle menu"
            aria-expanded={menuOpen}
          >
            <span></span><span></span><span></span>
          </button>
        </div>
      </nav>

      <div className={`lp-mobile-menu${menuOpen ? " open" : ""}`}>
        <a href="/adopt" onClick={onToggleMenu}>Adopt</a>
        <a href="/visit" onClick={onToggleMenu}>Visit</a>
        <a href="/volunteer" onClick={onToggleMenu}>Volunteer</a>
        <a href="/transparency" onClick={onToggleMenu}>Transparency</a>
        <a href="#donate" onClick={onToggleMenu}>Donate</a>
        <a href="#report" onClick={onToggleMenu}>Report a stray</a>
        <a href={isLoggedIn ? "/dashboard" : "/login"} onClick={onToggleMenu}>{isLoggedIn ? "Dashboard" : "Login"}</a>
      </div>
    </header>
  );
}

export function Hero({ eyebrow, heroTitle, heroSubtitle, bannerImage, stats, onMeetDogs, onReportStray }) {
  const [textRef, textIn] = useInView();
  const [cardRef, cardIn] = useInView();

  return (
    <section className="lp-hero">
      <div ref={textRef} className={`lp-reveal${textIn ? " is-visible" : ""}`}>
        <span className="lp-eyebrow">{eyebrow}</span>
        <h1>{heroTitle}</h1>
        <p className="lead">{heroSubtitle}</p>
        <div className="lp-hero-actions">
          <button className="lp-btn lp-btn-primary" onClick={onMeetDogs}>Browse adoptable Aspins</button>
          <button className="lp-btn lp-btn-ghost" onClick={onReportStray}>Report a stray</button>
        </div>
        <div className="lp-hero-stats">
          {stats.map((s) => (
            <div className="lp-hero-stat" key={s.label}>
              <div className="num">{s.value}</div>
              <div className="label">{s.label}</div>
            </div>
          ))}
        </div>
      </div>

      <div
        ref={cardRef}
        className={`lp-hero-card lp-reveal${cardIn ? " is-visible" : ""}`}
        style={{ "--reveal-delay": "0.12s" }}
      >
        <div className="photo-block">
          {bannerImage ? <img src={bannerImage} alt="SECASPI Shelter logo" /> : "Photo placeholder — Aspin portrait"}
        </div>
      </div>
    </section>
  );
}

export function Pathways({ onNavigateTo }) {
  const [headRef, headIn] = useInView();
  const [gridRef, gridIn] = useInView();

  return (
    <section id="how">
      <div className="lp-container">
        <div ref={headRef} className={`lp-section-head lp-reveal${headIn ? " is-visible" : ""}`}>
          <span className="lp-eyebrow">Three ways to help</span>
          <h2>Whatever you can give, it matters</h2>
          <p>Adoption isn't the only way to change an Aspin's story. Every pathway below directly supports a dog in our care.</p>
        </div>
        <div ref={gridRef} className={`lp-pathways lp-reveal-group${gridIn ? " is-visible" : ""}`}>
          {pathways.map((p) => (
            <div className={`lp-pathway-card lp-reveal-item ${p.key}`} key={p.key}>
              <div className="lp-pathway-icon" aria-hidden="true">{p.icon}</div>
              <h3>{p.title}</h3>
              <p>{p.desc}</p>
              <button className="link" onClick={() => onNavigateTo(p.target)}>{p.linkLabel}</button>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

export function FeaturedAnimals({ animals, onAdopt }) {
  const [headRef, headIn] = useInView();
  const [gridRef, gridIn] = useInView();

  return (
    <section id="animals">
      <div className="lp-container">
        <div ref={headRef} className={`lp-section-head lp-reveal${headIn ? " is-visible" : ""}`}>
          <span className="lp-eyebrow">Meet the dogs</span>
          <h2>Currently looking for homes</h2>
          <p>A few of the Aspins waiting in our shelter right now.</p>
        </div>
        <div ref={gridRef} className={`lp-featured-grid lp-reveal-group${gridIn ? " is-visible" : ""}`}>
          {animals.slice(0, 4).map((a) => {
            const photo = animalPhotoSrc(a.photo);
            return (
              <article className="lp-intake-card lp-reveal-item" key={a.id ?? a.name} onClick={onAdopt} role="button" tabIndex={0} onKeyDown={(e) => { if (e.key === "Enter") onAdopt(); }}>
                <div className="photo-block">
                  {photo ? <img src={photo} alt={a.name} /> : "Photo placeholder"}
                </div>
                <div className="body">
                  <div className="top-row">
                    <span className="name">{a.name}</span>
                    <span className="age">{a.age}</span>
                  </div>
                  <div className="tags">
                    {a.species && <span className="tag">{a.species}</span>}
                    {a.status && <span className="tag">{a.status}</span>}
                  </div>
                </div>
              </article>
            );
          })}
        </div>
        <div className="lp-featured-foot">
          <button className="lp-btn lp-btn-ghost" onClick={onAdopt}>See all available Aspins</button>
        </div>
      </div>
    </section>
  );
}

export function ImpactBand({ stats }) {
  const [ref, inView] = useInView();

  return (
    <section id="impact" className="lp-section">
      <div ref={ref} className={`lp-impact lp-reveal${inView ? " is-visible" : ""}`}>
        <div className="lp-impact-inner">
          {stats.map((s) => (
            <div key={s.label}>
              <div className="num">{s.value}</div>
              <div className="label">{s.label}</div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

export function Process() {
  const [headRef, headIn] = useInView();
  const [gridRef, gridIn] = useInView();

  return (
    <section className="lp-section">
      <div className="lp-container">
        <div ref={headRef} className={`lp-section-head lp-reveal${headIn ? " is-visible" : ""}`}>
          <span className="lp-eyebrow">The process</span>
          <h2>How a rescue becomes a homecoming</h2>
        </div>
        <div ref={gridRef} className={`lp-process lp-reveal-group${gridIn ? " is-visible" : ""}`}>
          {processSteps.map((s) => (
            <div className="lp-step lp-reveal-item" key={s.title}>
              <h3>{s.title}</h3>
              <p>{s.desc}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

export function RescueForm({ form, reportPhoto, reportState, onChange, onPhotoChange, onPinLocation, onSubmit }) {
  const [ref, inView] = useInView();

  return (
    <section id="report" className="lp-section">
      <div ref={ref} className={`lp-container lp-reveal${inView ? " is-visible" : ""}`} style={{ maxWidth: 680 }}>
        <div className="lp-section-head">
          <span className="lp-eyebrow">Emergency Rescue</span>
          <h2>Spotted a dog in distress?</h2>
          <p>Report it and our rescue team will respond fast.</p>
        </div>
        {reportState.status === "success" && (
          <div className="ui-success-msg">Thank you — your rescue report has been submitted. Our team will respond as fast as possible.</div>
        )}
        {reportState.status === "error" && (
          <div className="ui-error">{reportState.error}</div>
        )}
        <form onSubmit={onSubmit}>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16, marginBottom: 16 }}>
            <div><label className="ui-label">Your name</label><input className="ui-input" name="name" value={form.name} onChange={onChange} placeholder="Juan dela Cruz" /></div>
            <div><label className="ui-label">Contact</label><input className="ui-input" name="contact" value={form.contact} onChange={onChange} placeholder="09XX XXX XXXX" /></div>
          </div>
          <div className="ui-field">
            <label className="ui-label ui-label-required">Location</label>
            <input className="ui-input" name="location" required value={form.location} onChange={onChange} placeholder="Be specific: house no. / street, landmark, barangay, city" />
            <div style={{ fontSize: 12, color: "var(--lp-ink-soft)", marginTop: 6 }}>
              The more detailed, the faster our team finds the animal. Pin the exact spot on the map below 👇
            </div>

            <div style={{ display: "flex", gap: 8, alignItems: "center", margin: "8px 0" }}>
              <button
                type="button"
                className="lp-btn lp-btn-ghost"
                style={{ fontSize: 13 }}
                onClick={() => {
                  if (!navigator.geolocation) return;
                  navigator.geolocation.getCurrentPosition(
                    (pos) => onPinLocation(pos.coords.latitude, pos.coords.longitude),
                    () => {},
                  );
                }}
              >
                📍 Use my current location
              </button>
              <span style={{ fontSize: 12, color: form.latitude ? "var(--lp-brand, #c1612e)" : "var(--lp-ink-soft)" }}>
                {form.latitude ? "Exact spot pinned ✓" : "No pin yet — tap the map"}
              </span>
            </div>

            <div style={{ height: 260, borderRadius: 12, overflow: "hidden", border: "1px solid var(--lp-line, #e7ddc9)" }}>
              <MapContainer center={form.latitude ? [form.latitude, form.longitude] : RESCUE_MAP_DEFAULT} zoom={form.latitude ? 16 : 12} style={{ height: "100%", width: "100%" }} scrollWheelZoom>
                <TileLayer attribution="&copy; OpenStreetMap contributors" url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
                <ClickToPin onPin={onPinLocation} />
                <RecenterOnPin lat={form.latitude} lng={form.longitude} />
                {form.latitude != null && form.longitude != null && (
                  <CircleMarker center={[form.latitude, form.longitude]} radius={10} pathOptions={{ color: "#c0392b", fillOpacity: 0.85 }} />
                )}
              </MapContainer>
            </div>
          </div>
          <div className="ui-field"><label className="ui-label">Condition</label><select className="ui-select" name="condition" value={form.condition} onChange={onChange}><option>Injured or sick</option><option>Stray / no owner</option><option>Abandoned</option><option>In immediate danger</option><option>Other</option></select></div>
          <div className="ui-field"><label className="ui-label">Details</label><textarea className="ui-textarea" name="details" value={form.details} onChange={onChange} placeholder="Describe what you see..." /></div>
          <div className="ui-field">
            <label className="ui-label">Photo (optional)</label>
            <input className="ui-input" type="file" accept="image/*" onChange={onPhotoChange} />
            {reportPhoto && <span style={{ display: "block", fontSize: 13, color: "var(--lp-ink-soft)", marginTop: 6 }}>Selected: {reportPhoto.name}</span>}
          </div>
          <button type="submit" className="lp-btn lp-btn-primary" disabled={reportState.status === "loading"}>
            {reportState.status === "loading" ? "Sending..." : "Send Report"}
          </button>
        </form>
      </div>
    </section>
  );
}

export function TopSupporters({ topDonors }) {
  const [headRef, headIn] = useInView();
  const [gridRef, gridIn] = useInView();

  if (!topDonors?.length) return null;

  return (
    <section className="lp-section">
      <div className="lp-container">
        <div ref={headRef} className={`lp-section-head lp-reveal${headIn ? " is-visible" : ""}`}>
          <span className="lp-eyebrow">Community Impact</span>
          <h2>Our Top Supporters</h2>
          <p>Donors who've gone above and beyond for our rescues.</p>
        </div>
        <div ref={gridRef} className={`lp-donors lp-reveal-group${gridIn ? " is-visible" : ""}`}>
          {topDonors.map((d, i) => (
            <div className="lp-donor-card lp-reveal-item" key={`${d.name}-${i}`}>
              <div className="lp-donor-rank">#{i + 1}</div>
              <div className="lp-donor-name">{d.name}</div>
              <div className="lp-donor-amt">₱{d.total.toLocaleString()}</div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

export function DonateCta({ amt, onSelectAmount, onDonate, onReportStray }) {
  const [ref, inView] = useInView();

  return (
    <section id="donate" className="lp-section">
      <div ref={ref} className={`lp-cta-banner lp-reveal${inView ? " is-visible" : ""}`}>
        <h2>Help us rescue the next Aspin off the street.</h2>
        <p>Your donation funds food, vaccines, and shelter for dogs waiting for their second chance.</p>
        <div className="lp-amounts">
          {donationAmounts.map((a) => (
            <button key={a} className={`lp-amt${amt === a ? " active" : ""}`} onClick={() => onSelectAmount(a)}>{a}</button>
          ))}
        </div>
        <div className="lp-cta-actions">
          <button className="lp-btn lp-btn-primary" onClick={onDonate}>Donate {amt}</button>
          <button className="lp-btn lp-btn-ghost" onClick={onReportStray}>Report a stray instead</button>
        </div>
      </div>
    </section>
  );
}

export function SiteFooter({ shelterName, settings, address }) {
  return (
    <footer className="lp-footer">
      <div className="lp-container">
        <div className="lp-footer-grid">
          <div className="lp-footer-brand">
            <a href="/" className="lp-logo"><span className="lp-logo-mark" aria-hidden="true">🐾</span>{shelterName}</a>
            <p>{settings.about_us_content || "Rescuing and rehoming the Philippines' native Aspin dogs."}</p>
            {(settings.social_facebook || settings.social_instagram || settings.social_twitter) && (
              <div style={{ display: "flex", gap: 14, marginTop: 16 }}>
                {settings.social_facebook && <a href={settings.social_facebook} target="_blank" rel="noreferrer">Facebook</a>}
                {settings.social_instagram && <a href={settings.social_instagram} target="_blank" rel="noreferrer">Instagram</a>}
                {settings.social_twitter && <a href={settings.social_twitter} target="_blank" rel="noreferrer">Twitter</a>}
              </div>
            )}
          </div>
          <div className="lp-footer-col">
            <h4>Shelter</h4>
            <ul><li><a href="/adopt">Adopt</a></li><li><a href="#donate">Donate</a></li><li><a href="#how">How it works</a></li></ul>
          </div>
          <div className="lp-footer-col">
            <h4>Get involved</h4>
            <ul><li><a href="/register">Volunteer</a></li><li><a href="#report">Report a stray</a></li><li><a href="/adopt">Foster a dog</a></li></ul>
          </div>
          <div className="lp-footer-col">
            <h4>Contact</h4>
            <ul>
              {settings.contact_email && <li><a href={`mailto:${settings.contact_email}`}>{settings.contact_email}</a></li>}
              {settings.contact_phone && <li>{settings.contact_phone}</li>}
              <li>{address}</li>
            </ul>
          </div>
        </div>
        <div className="lp-footer-bottom">
          <span>© {new Date().getFullYear()} {shelterName}. All rights reserved.</span>
          <span>Built with care for Aspins everywhere.</span>
        </div>
      </div>
    </footer>
  );
}
