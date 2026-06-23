import { useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { getFeaturedAnimals, getImpactStats } from "../../lib/publicHomeApi.js";
import { createReport } from "../../lib/rescueApi.js";
import { auth } from "../../lib/auth.js";
import { getPublicSettings, settingImageUrl } from "../../lib/settingsApi.js";
import "./LandingPage.css";

const animalsFallback = [
  { id: 1, name: "Bingo", age: "2 yrs", species: "Dog", status: "Available for adoption", photo: null },
  { id: 2, name: "Maya", age: "1 yr", species: "Dog", status: "Available for adoption", photo: null },
  { id: 3, name: "Boss", age: "3 yrs", species: "Dog", status: "Available for adoption", photo: null },
  { id: 4, name: "Luna", age: "6 mos", species: "Dog", status: "Available for adoption", photo: null },
];

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

const donationAmounts = ["₱100", "₱300", "₱500", "₱1,000", "₱2,500"];

const URGENCY_BY_CONDITION = {
  "Injured or sick": "high",
  "Stray / no owner": "low",
  "Abandoned": "medium",
  "In immediate danger": "critical",
  "Other": "medium",
};

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

function Navbar({ shelterName, isLoggedIn, menuOpen, scrolled, onToggleMenu, onNavigate }) {
  return (
    <header className={`lp-header${scrolled ? " scrolled" : ""}`}>
      <nav className="lp-nav" aria-label="Primary">
        <a href="/" className="lp-logo">
          <span className="lp-logo-mark" aria-hidden="true">🐾</span>
          {shelterName}
        </a>
        <ul className="lp-nav-links">
          <li><a href="/adopt">Adopt</a></li>
          <li><a href="#how">How it works</a></li>
          <li><a href="#impact">Impact</a></li>
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
        <a href="#how" onClick={onToggleMenu}>How it works</a>
        <a href="#impact" onClick={onToggleMenu}>Impact</a>
        <a href="/transparency" onClick={onToggleMenu}>Transparency</a>
        <a href="#donate" onClick={onToggleMenu}>Donate</a>
        <a href="#report" onClick={onToggleMenu}>Report a stray</a>
        <a href={isLoggedIn ? "/dashboard" : "/login"} onClick={onToggleMenu}>{isLoggedIn ? "Dashboard" : "Login"}</a>
      </div>
    </header>
  );
}

function Hero({ eyebrow, heroTitle, heroSubtitle, bannerImage, stats, onMeetDogs, onReportStray }) {
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

function Pathways({ onNavigateTo }) {
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

function FeaturedAnimals({ animals, onAdopt }) {
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

function ImpactBand({ stats }) {
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

function Process() {
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

function RescueForm({ form, reportPhoto, reportState, onChange, onPhotoChange, onSubmit }) {
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
          <div className="ui-field"><label className="ui-label ui-label-required">Location</label><input className="ui-input" name="location" required value={form.location} onChange={onChange} placeholder="Street, Barangay, City" /></div>
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

function TopSupporters({ topDonors }) {
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

function DonateCta({ amt, onSelectAmount, onDonate, onReportStray }) {
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

function SiteFooter({ shelterName, settings, address }) {
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

export default function LandingPage() {
  const navigate = useNavigate();
  const [amt, setAmt] = useState(donationAmounts[1]);
  const [animals, setAnimals] = useState(animalsFallback);
  const [impact, setImpact] = useState(null);
  const [settings, setSettings] = useState({});
  const [menuOpen, setMenuOpen] = useState(false);
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [form, setForm] = useState({ name: "", contact: "", location: "", condition: "Injured or sick", details: "" });
  const [reportPhoto, setReportPhoto] = useState(null);
  const [reportState, setReportState] = useState({ status: "idle", error: "" });
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await getFeaturedAnimals();
        if (!mounted) return;
        setAnimals(Array.isArray(data?.animals) && data.animals.length ? data.animals : animalsFallback);
      } catch (err) {
        console.error("Failed to load featured animals:", err);
      }
    })();
    return () => { mounted = false; };
  }, []);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await getImpactStats();
        if (mounted) setImpact(data);
      } catch (err) {
        console.error("Failed to load impact stats:", err);
      }
    })();
    return () => { mounted = false; };
  }, []);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        await auth.me();
        if (mounted) setIsLoggedIn(true);
      } catch {
        if (mounted) setIsLoggedIn(false);
      }
    })();
    return () => { mounted = false; };
  }, []);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await getPublicSettings();
        if (mounted) setSettings(data || {});
      } catch (err) {
        console.error("Failed to load site settings:", err);
      }
    })();
    return () => { mounted = false; };
  }, []);

  const shelterName = settings.shelter_name || "SECASPI Shelter";
  const heroTitle = settings.hero_title || "Every Aspin deserves a home, not just a street.";
  const heroSubtitle = settings.hero_subtitle || "SECASPI rescues, rehabilitates, and rehomes the Philippines' native dogs — the Aspin — connecting stray animals with people ready to give them a second chance.";
  const bannerImage = settings.banner_image_path ? settingImageUrl(settings.banner_image_path) : "/hero-dog.jpg";
  const address = settings.address || "Calamba, Laguna, Philippines";
  const eyebrow = address.split(",").slice(0, 2).join(",").trim() || "Calamba, Laguna";

  // Derived "currently in care" = rescued minus already-adopted, since the public API
  // doesn't expose a literal in-care count. Used for both the hero and impact band.
  const inCare = impact ? Math.max(0, (impact.animals_rescued ?? 0) - (impact.animals_adopted ?? 0)) : null;

  const heroStats = [
    { label: "Aspins rehomed", value: impact ? `${impact.animals_adopted}` : "312" },
    { label: "Currently in care", value: inCare != null ? `${inCare}` : "48" },
    { label: "Active volunteers", value: impact?.volunteers_count != null ? `${impact.volunteers_count}` : "96" },
  ];

  const impactStats = [
    { label: "Dogs rehomed since 2021", value: impact ? `${impact.animals_adopted}` : "312" },
    { label: "In active care today", value: inCare != null ? `${inCare}` : "48" },
    { label: "Registered volunteers", value: impact?.volunteers_count != null ? `${impact.volunteers_count}` : "96" },
    { label: "Raised for shelter care", value: impact ? `₱${Number(impact.donations_raised || 0).toLocaleString()}` : "₱1.2M" },
  ];

  const scrollTo = (id) => document.getElementById(id)?.scrollIntoView({ behavior: "smooth" });
  const handleChange = (e) => setForm({ ...form, [e.target.name]: e.target.value });
  const handlePhotoChange = (e) => setReportPhoto(e.target.files?.[0] || null);
  const handleSubmit = async (e) => {
    e.preventDefault();
    setReportState({ status: "loading", error: "" });
    try {
      const fd = new FormData();
      fd.append("reporter_name", form.name);
      fd.append("contact_number", form.contact);
      fd.append("location", form.location);
      fd.append("description", form.details);
      fd.append("urgency", URGENCY_BY_CONDITION[form.condition] || "medium");
      if (reportPhoto) fd.append("photo", reportPhoto);

      await createReport(fd);

      setReportState({ status: "success", error: "" });
      setForm({ name: "", contact: "", location: "", condition: "Injured or sick", details: "" });
      setReportPhoto(null);
    } catch (err) {
      setReportState({ status: "error", error: err?.message || "Failed to submit report. Please try again." });
    }
  };

  return (
    <div className="landingPage">
      <a href="#main" className="skip-link">Skip to content</a>

      <Navbar
        shelterName={shelterName}
        isLoggedIn={isLoggedIn}
        menuOpen={menuOpen}
        scrolled={scrolled}
        onToggleMenu={() => setMenuOpen((open) => !open)}
        onNavigate={(path) => { setMenuOpen(false); navigate(path); }}
      />

      <main id="main">
        <Hero
          eyebrow={eyebrow}
          heroTitle={heroTitle}
          heroSubtitle={heroSubtitle}
          bannerImage={bannerImage}
          stats={heroStats}
          onMeetDogs={() => scrollTo("animals")}
          onReportStray={() => scrollTo("report")}
        />
        <Pathways onNavigateTo={(id) => scrollTo(id)} />
        <FeaturedAnimals animals={animals} onAdopt={() => navigate("/adopt")} />
        <ImpactBand stats={impactStats} />
        <Process />
        <RescueForm
          form={form}
          reportPhoto={reportPhoto}
          reportState={reportState}
          onChange={handleChange}
          onPhotoChange={handlePhotoChange}
          onSubmit={handleSubmit}
        />
        <TopSupporters topDonors={impact?.top_donors} />
        <DonateCta
          amt={amt}
          onSelectAmount={setAmt}
          onDonate={() => navigate("/donate", { state: { amount: Number(amt.replace(/[^0-9]/g, "")) } })}
          onReportStray={() => scrollTo("report")}
        />
      </main>

      <SiteFooter shelterName={shelterName} settings={settings} address={address} />
    </div>
  );
}
