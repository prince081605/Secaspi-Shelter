import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { getFeaturedAnimals, getImpactStats } from "../../lib/publicHomeApi.js";
import { createReport } from "../../lib/rescueApi.js";
import { auth } from "../../lib/auth.js";
import { getPublicSettings, settingImageUrl } from "../../lib/settingsApi.js";
import "./LandingPage.css";

const TAG_VARIANT = { av: "brand", urg: "amber", new: "sky" };

const animalsFallback = [
  {id:1,name:"Bingo",gender:"Male",age:"2 yrs",size:"Medium",status:"Available",tag:"av",tagLabel:"Available",photo:null},
  {id:2,name:"Maya",gender:"Female",age:"1 yr",size:"Small",status:"Urgent",tag:"urg",tagLabel:"Urgent",photo:null},
  {id:3,name:"Boss",gender:"Male",age:"3 yrs",size:"Large",status:"New",tag:"new",tagLabel:"New",photo:null}
];

const steps = [
  {num:"STEP 1",icon:"🔍",title:"Browse & Choose",desc:"Explore our available Aspins and find your perfect match."},
  {num:"STEP 2",icon:"📝",title:"Submit Application",desc:"Fill out our adoption form. We check to ensure the right fit."},
  {num:"STEP 3",icon:"🤝",title:"Meet & Greet",desc:"Visit our shelter to meet your potential new best friend."},
  {num:"STEP 4",icon:"🏠",title:"Welcome Home",desc:"Complete paperwork and bring your Aspin to their forever home."}
];

const donationAmounts = ["₱100","₱300","₱500","₱1,000","₱2,500"];

const URGENCY_BY_CONDITION = {
  "Injured or sick": "high",
  "Stray / no owner": "low",
  "Abandoned": "medium",
  "In immediate danger": "critical",
  "Other": "medium",
};

function Navbar({ shelterName, isLoggedIn, scrolled, menuOpen, onToggleMenu, onNavigate }) {
  return (
    <header>
      <nav className={`ss-nav${scrolled ? " scrolled" : ""}`}>
        <div className="ss-logo">{shelterName}</div>
        <ul className="ss-nav-links">
          <li><a href="/adopt">Adopt</a></li>
          <li><a href="#rescue">Rescue</a></li>
          <li><a href="#donate">Donate</a></li>
          <li><a href="#about">About</a></li>
        </ul>
        <div className="ss-nav-actions">
          {isLoggedIn ? (
            <button className="ss-nav-ghost" onClick={() => onNavigate("/dashboard")}>Profile</button>
          ) : (
            <button className="ss-nav-ghost" onClick={() => onNavigate("/login")}>Login</button>
          )}
          <button className="ss-nav-cta" onClick={() => onNavigate("/adopt")}>Find a Dog</button>
          <button
            className={`ss-hamburger${menuOpen ? " open" : ""}`}
            onClick={onToggleMenu}
            aria-label="Toggle menu"
            aria-expanded={menuOpen}
          >
            <span></span><span></span><span></span>
          </button>
        </div>
      </nav>

      {menuOpen && (
        <div className="ss-mobile-menu open">
          <a href="/adopt" onClick={onToggleMenu}>Adopt</a>
          <a href="#rescue" onClick={onToggleMenu}>Rescue</a>
          <a href="#donate" onClick={onToggleMenu}>Donate</a>
          <a href="#about" onClick={onToggleMenu}>About</a>
          {isLoggedIn ? (
            <a href="/dashboard" onClick={onToggleMenu}>Profile</a>
          ) : (
            <a href="/login" onClick={onToggleMenu}>Login</a>
          )}
          <button className="ss-nav-cta" onClick={() => onNavigate("/adopt")}>Find a Dog</button>
        </div>
      )}
    </header>
  );
}

function Hero({ heroTitle, heroSubtitle, bannerImage, onMeetDogs, onReportRescue }) {
  return (
    <section className="ss-hero">
      <div className="ss-hero-left">
        <span className="ss-eyebrow">🐾 Rescuing Aspins since 2018</span>
        <h1 className="ss-hero-title">{heroTitle}</h1>
        <p className="ss-hero-sub">{heroSubtitle}</p>
        <div className="ss-hero-actions">
          <button className="ss-btn-primary" onClick={onMeetDogs}>Meet Our Dogs</button>
          <button className="ss-btn-secondary" onClick={onReportRescue}>Report a Rescue</button>
        </div>
      </div>
      <div className="ss-hero-right">
        <div className="ss-hero-visual">
          <img
            src={bannerImage}
            alt="A rescued dog ready for adoption"
            className="ss-hero-photo"
            onError={(e) => { e.currentTarget.style.display = "none"; }}
          />
          <div className="ss-hero-dog">🐾</div>
        </div>
      </div>
    </section>
  );
}

function StatsBar({ impact }) {
  return (
    <section className="ss-stats" aria-label="Shelter impact statistics">
      <div className="ss-stats-inner">
        <div className="ss-stat"><div className="ss-stat-num">{impact ? `${impact.animals_rescued}+` : "1,200+"}</div><div className="ss-stat-label">Dogs Rescued</div></div>
        <div className="ss-stat"><div className="ss-stat-num">{impact ? `${impact.animals_adopted}+` : "890+"}</div><div className="ss-stat-label">Successful Adoptions</div></div>
        <div className="ss-stat"><div className="ss-stat-num">{impact?.success_rate != null ? `${impact.success_rate}%` : "98%"}</div><div className="ss-stat-label">Success Rate</div></div>
      </div>
    </section>
  );
}

function FeaturedAnimals({ animals, onAdopt }) {
  return (
    <section className="ss-section" id="animals">
      <div className="ss-section-header">
        <p className="ss-eyebrow-c">Available for Adoption</p>
        <h2 className="ss-section-title">Meet your new best friend</h2>
        <p className="ss-section-sub">All our dogs are vaccinated, dewormed, and ready for a loving home.</p>
      </div>
      <div className="ss-animals">
        {animals.slice(0, 3).map((a) => (
          <div className="ss-animal-card" key={a.name ?? a.id}>
            <div className="ss-animal-img">
              {a.photo ? <img src={a.photo.startsWith('http') ? a.photo : `${import.meta.env.VITE_API_BASE_URL}/storage/${a.photo}`} alt={a.name} /> : "🐕"}
            </div>
            <div className="ss-animal-info">
              <div className="ss-animal-name">{a.name}</div>
              <div className="ss-animal-meta"><span>{a.gender}</span><span>{a.age}</span><span>{a.size}</span></div>
              <span className={`ui-tag ui-tag-${TAG_VARIANT[a.tag] || 'muted'}`}>{a.tagLabel}</span>
              <button className="ss-adopt-btn" onClick={onAdopt}>Start Adoption</button>
            </div>
          </div>
        ))}
      </div>
      <div className="ss-view-all">
        <button className="ss-btn-secondary" onClick={onAdopt}>View All Dogs →</button>
      </div>
    </section>
  );
}

function HowItWorks() {
  return (
    <section className="ss-section alt">
      <div className="ss-section-header">
        <p className="ss-eyebrow-c">How It Works</p>
        <h2 className="ss-section-title">Adopting is simple</h2>
      </div>
      <div className="ss-steps">
        {steps.map((s) => (
          <div className="ss-step-card" key={s.num}>
            <div className="ss-step-icon">{s.icon}</div>
            <div className="ss-step-num">{s.num}</div>
            <div className="ss-step-title">{s.title}</div>
            <div className="ss-step-desc">{s.desc}</div>
          </div>
        ))}
      </div>
    </section>
  );
}

function RescueForm({ form, reportPhoto, reportState, onChange, onPhotoChange, onSubmit }) {
  return (
    <section className="ss-rescue" id="rescue">
      <div>
        <p className="ss-eyebrow-c">Emergency Rescue</p>
        <h2 className="ss-section-title">Spotted a dog in distress?</h2>
        <p className="ss-section-sub" style={{ marginBottom: "1.5rem" }}>Report it and our rescue team will respond fast.</p>
        {reportState.status === "success" && (
          <div className="ui-success-msg">Thank you — your rescue report has been submitted. Our team will respond as fast as possible.</div>
        )}
        {reportState.status === "error" && (
          <div className="ui-error">{reportState.error}</div>
        )}
        <form onSubmit={onSubmit}>
          <div className="ss-form-r">
            <div><label className="ui-label">Your name</label><input className="ui-input" name="name" value={form.name} onChange={onChange} placeholder="Juan dela Cruz" /></div>
            <div><label className="ui-label">Contact</label><input className="ui-input" name="contact" value={form.contact} onChange={onChange} placeholder="09XX XXX XXXX" /></div>
          </div>
          <div className="ss-form-f"><label className="ui-label ui-label-required">Location</label><input className="ui-input" name="location" required value={form.location} onChange={onChange} placeholder="Street, Barangay, City" /></div>
          <div className="ss-form-f"><label className="ui-label">Condition</label><select className="ui-select" name="condition" value={form.condition} onChange={onChange}><option>Injured or sick</option><option>Stray / no owner</option><option>Abandoned</option><option>In immediate danger</option><option>Other</option></select></div>
          <div className="ss-form-f"><label className="ui-label">Details</label><textarea className="ui-textarea" name="details" value={form.details} onChange={onChange} placeholder="Describe what you see..." /></div>
          <div className="ss-form-f">
            <label className="ui-label">Photo (optional)</label>
            <input className="ui-input" type="file" accept="image/*" onChange={onPhotoChange} />
            {reportPhoto && <span className="ss-file-label">Selected: {reportPhoto.name}</span>}
          </div>
          <button type="submit" className="ss-btn-primary" disabled={reportState.status === "loading"}>
            {reportState.status === "loading" ? "Sending..." : "Send Report"}
          </button>
        </form>
      </div>
    </section>
  );
}

function TopSupporters({ topDonors }) {
  if (!topDonors?.length) return null;

  return (
    <section className="ss-section">
      <div className="ss-section-header">
        <p className="ss-eyebrow-c">Community Impact</p>
        <h2 className="ss-section-title">Our Top Supporters</h2>
        <p className="ss-section-sub">Donors who've gone above and beyond for our rescues.</p>
      </div>
      <div className="ss-donors">
        {topDonors.map((d, i) => (
          <div className="ss-donor-card" key={`${d.name}-${i}`}>
            <div className="ss-donor-rank">#{i + 1}</div>
            <div className="ss-donor-name">{d.name}</div>
            <div className="ss-donor-amt">₱{d.total.toLocaleString()}</div>
          </div>
        ))}
      </div>
    </section>
  );
}

function DonateCta({ amt, onSelectAmount, onDonate }) {
  return (
    <section className="ss-donate" id="donate">
      <div className="ss-donate-card">
        <p className="ss-eyebrow-c">Support the Mission</p>
        <h2 className="ss-donateT">Every peso feeds, heals, and shelters</h2>
        <p className="ss-donateS">Your donation covers vet care, food, and shelter operations.</p>
        <div className="ss-donateA">
          {donationAmounts.map((a) => (
            <button key={a} className={`ss-amt${amt === a ? " active" : ""}`} onClick={() => onSelectAmount(a)}>{a}</button>
          ))}
        </div>
        <button className="ss-donateB" onClick={onDonate}>Donate {amt}</button>
      </div>
    </section>
  );
}

function SiteFooter({ shelterName, settings, address }) {
  return (
    <footer className="ss-footer" id="about">
      <div className="ss-footM">
        <div className="ss-footB">
          <div className="ss-logo">{shelterName}</div>
          <p>{settings.about_us_content || "Rescuing and rehoming Aspins since 2018."}</p>
          {(settings.social_facebook || settings.social_instagram || settings.social_twitter) && (
            <div style={{ display: "flex", gap: 10, marginTop: 10 }}>
              {settings.social_facebook && <a href={settings.social_facebook} target="_blank" rel="noreferrer">Facebook</a>}
              {settings.social_instagram && <a href={settings.social_instagram} target="_blank" rel="noreferrer">Instagram</a>}
              {settings.social_twitter && <a href={settings.social_twitter} target="_blank" rel="noreferrer">Twitter</a>}
            </div>
          )}
        </div>
        <div className="ss-footCol">
          <h4>Adopt</h4>
          <ul><li><a href="#animals">Available Dogs</a></li><li><a href="#">Process</a></li><li><a href="#">FAQs</a></li></ul>
        </div>
        <div className="ss-footCol">
          <h4>Get Involved</h4>
          <ul><li><a href="#donate">Donate</a></li><li><a href="#">Volunteer</a></li><li><a href="#rescue">Report Rescue</a></li></ul>
        </div>
        <div className="ss-footCol">
          <h4>Contact</h4>
          <ul>
            {settings.contact_email && <li><a href={`mailto:${settings.contact_email}`}>{settings.contact_email}</a></li>}
            {settings.contact_phone && <li>{settings.contact_phone}</li>}
            <li>{address}</li>
          </ul>
        </div>
      </div>
      <div className="ss-footBot">
        <p>© {new Date().getFullYear()} {shelterName}. All rights reserved.</p>
        <p>{address}</p>
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
  const [scrolled, setScrolled] = useState(false);
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [form, setForm] = useState({ name: "", contact: "", location: "", condition: "Injured or sick", details: "" });
  const [reportPhoto, setReportPhoto] = useState(null);
  const [reportState, setReportState] = useState({ status: "idle", error: "" });

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await getFeaturedAnimals();
        if (!mounted) return;
        setAnimals(Array.isArray(data?.animals) ? data.animals : animalsFallback);
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

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const shelterName = settings.shelter_name || "SECASPI Shelter";
  const heroTitle = settings.hero_title || "Every Aspin deserves a forever home.";
  const heroSubtitle = settings.hero_subtitle || "We rescue, rehabilitate, and rehome native Philippine dogs. Your adoption changes two lives: theirs, and yours.";
  const bannerImage = settings.banner_image_path ? settingImageUrl(settings.banner_image_path) : "/hero-dog.jpg";
  const address = settings.address || "Calamba, Laguna, Philippines";

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
      <Navbar
        shelterName={shelterName}
        isLoggedIn={isLoggedIn}
        scrolled={scrolled}
        menuOpen={menuOpen}
        onToggleMenu={() => setMenuOpen((open) => !open)}
        onNavigate={(path) => { setMenuOpen(false); navigate(path); }}
      />

      <main>
        <Hero
          heroTitle={heroTitle}
          heroSubtitle={heroSubtitle}
          bannerImage={bannerImage}
          onMeetDogs={() => scrollTo("animals")}
          onReportRescue={() => scrollTo("rescue")}
        />
        <StatsBar impact={impact} />
        <FeaturedAnimals animals={animals} onAdopt={() => navigate("/adopt")} />
        <HowItWorks />
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
        />
      </main>

      <SiteFooter shelterName={shelterName} settings={settings} address={address} />
    </div>
  );
}
