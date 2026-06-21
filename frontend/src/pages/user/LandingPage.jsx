import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { getFeaturedAnimals, getImpactStats } from "../../lib/publicHomeApi.js";
import { createReport } from "../../lib/rescueApi.js";
import { auth } from "../../lib/auth.js";
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

export default function LandingPage() {
  const navigate = useNavigate();
  const [amt, setAmt] = useState(donationAmounts[1]);
  const [animals, setAnimals] = useState(animalsFallback);
  const [impact, setImpact] = useState(null);
  const [menuOpen, setMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const [isLoggedIn, setIsLoggedIn] = useState(false);

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
    const onScroll = () => setScrolled(window.scrollY > 8);
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const [form, setForm] = useState({name:"",contact:"",location:"",condition:"Injured or sick",details:""});
  const [reportPhoto, setReportPhoto] = useState(null);
  const [reportState, setReportState] = useState({status:"idle",error:""});

  const URGENCY_BY_CONDITION = {
    "Injured or sick": "high",
    "Stray / no owner": "low",
    "Abandoned": "medium",
    "In immediate danger": "critical",
    "Other": "medium",
  };

  const scrollTo = (id) => {
    setMenuOpen(false);
    document.getElementById(id)?.scrollIntoView({behavior:"smooth"});
  };
  const handleChange = (e) => setForm({...form,[e.target.name]:e.target.value});
  const handlePhotoChange = (e) => setReportPhoto(e.target.files?.[0] || null);
  const handleSubmit = async (e) => {
    e.preventDefault();
    setReportState({status:"loading",error:""});
    try {
      const fd = new FormData();
      fd.append("reporter_name", form.name);
      fd.append("contact_number", form.contact);
      fd.append("location", form.location);
      fd.append("description", form.details);
      fd.append("urgency", URGENCY_BY_CONDITION[form.condition] || "medium");
      if (reportPhoto) fd.append("photo", reportPhoto);

      await createReport(fd);

      setReportState({status:"success",error:""});
      setForm({name:"",contact:"",location:"",condition:"Injured or sick",details:""});
      setReportPhoto(null);
    } catch (err) {
      setReportState({status:"error",error: err?.message || "Failed to submit report. Please try again."});
    }
  };

  return (
    <div className="landingPage">
      <nav className={`ss-nav${scrolled ? " scrolled" : ""}`}>
        <div className="ss-logo">SECASPI <span>Shelter</span></div>
        <ul className="ss-nav-links">
          <li><a href="/adopt">Adopt</a></li>
          <li><a href="#rescue">Rescue</a></li>
          <li><a href="#donate">Donate</a></li>
          <li><a href="#about">About</a></li>
        </ul>
        <div className="ss-nav-actions">
          {isLoggedIn ? (
            <button className="ss-nav-ghost" onClick={() => navigate("/dashboard")}>Profile</button>
          ) : (
            <button className="ss-nav-ghost" onClick={() => navigate("/login")}>Login</button>
          )}
          <button className="ss-nav-cta" onClick={() => navigate("/adopt")}>Find a Dog</button>
          <button
            className={`ss-hamburger${menuOpen ? " open" : ""}`}
            onClick={() => setMenuOpen((o) => !o)}
            aria-label="Toggle menu"
            aria-expanded={menuOpen}
          >
            <span></span><span></span><span></span>
          </button>
        </div>
      </nav>

      {menuOpen && (
        <div className="ss-mobile-menu open">
          <a href="/adopt" onClick={() => setMenuOpen(false)}>Adopt</a>
          <a href="#rescue" onClick={() => scrollTo("rescue")}>Rescue</a>
          <a href="#donate" onClick={() => scrollTo("donate")}>Donate</a>
          <a href="#about" onClick={() => setMenuOpen(false)}>About</a>
          {isLoggedIn ? (
            <a href="/dashboard" onClick={() => setMenuOpen(false)}>Profile</a>
          ) : (
            <a href="/login" onClick={() => setMenuOpen(false)}>Login</a>
          )}
          <button className="ss-nav-cta" onClick={() => { setMenuOpen(false); navigate("/adopt"); }}>Find a Dog</button>
        </div>
      )}

      <section className="ss-hero">
        <div className="ss-hero-left">
          <span className="ss-eyebrow">🐾 Rescuing Aspins since 2018</span>
          <h1 className="ss-hero-title">Every Aspin deserves a <span className="accent">forever home.</span></h1>
          <p className="ss-hero-sub">We rescue, rehabilitate, and rehome native Philippine dogs. Your adoption changes two lives: theirs, and yours.</p>
          <div className="ss-hero-actions">
            <button className="ss-btn-primary" onClick={() => scrollTo("animals")}>Meet Our Dogs</button>
            <button className="ss-btn-secondary" onClick={() => scrollTo("rescue")}>Report a Rescue</button>
          </div>
        </div>
        <div className="ss-hero-right">
          <div className="ss-hero-visual">
            <img
              src="/hero-dog.jpg"
              alt="A rescued dog ready for adoption"
              className="ss-hero-photo"
              onError={(e) => { e.currentTarget.style.display = "none"; }}
            />
            <div className="ss-hero-dog">🐾</div>
          </div>
        </div>
      </section>

      <section className="ss-stats">
        <div className="ss-stats-inner">
          <div className="ss-stat"><div className="ss-stat-num">{impact ? `${impact.animals_rescued}+` : "1,200+"}</div><div className="ss-stat-label">Dogs Rescued</div></div>
          <div className="ss-stat"><div className="ss-stat-num">{impact ? `${impact.animals_adopted}+` : "890+"}</div><div className="ss-stat-label">Successful Adoptions</div></div>
          <div className="ss-stat"><div className="ss-stat-num">{impact?.success_rate != null ? `${impact.success_rate}%` : "98%"}</div><div className="ss-stat-label">Success Rate</div></div>
        </div>
      </section>

      <section className="ss-section" id="animals">
        <div className="ss-section-header">
          <p className="ss-eyebrow-c">Available for Adoption</p>
          <h2 className="ss-section-title">Meet your new best friend</h2>
          <p className="ss-section-sub">All our dogs are vaccinated, dewormed, and ready for a loving home.</p>
        </div>
        <div className="ss-animals">
          {animals.slice(0,3).map((a) => (
            <div className="ss-animal-card" key={a.name ?? a.id}>
              <div className="ss-animal-img">
                {a.photo ? <img src={a.photo.startsWith('http') ? a.photo : `${import.meta.env.VITE_API_BASE_URL}/storage/${a.photo}`} alt={a.name}/> : "🐕"}
              </div>
              <div className="ss-animal-info">
                <div className="ss-animal-name">{a.name}</div>
                <div className="ss-animal-meta"><span>{a.gender}</span><span>{a.age}</span><span>{a.size}</span></div>
                <span className={`ui-tag ui-tag-${TAG_VARIANT[a.tag] || 'muted'}`}>{a.tagLabel}</span>
                <button className="ss-adopt-btn" onClick={() => navigate("/adopt")}>Start Adoption</button>
              </div>
            </div>
          ))}
        </div>
        <div className="ss-view-all">
          <button className="ss-btn-secondary" onClick={() => navigate("/adopt")}>View All Dogs →</button>
        </div>
      </section>

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

      <section className="ss-rescue" id="rescue">
        <div>
          <p className="ss-eyebrow-c">Emergency Rescue</p>
          <h2 className="ss-section-title">Spotted a dog in distress?</h2>
          <p className="ss-section-sub" style={{marginBottom:"1.5rem"}}>Report it and our rescue team will respond fast.</p>
          {reportState.status === "success" && (
            <div className="ui-success-msg">Thank you — your rescue report has been submitted. Our team will respond as fast as possible.</div>
          )}
          {reportState.status === "error" && (
            <div className="ui-error">{reportState.error}</div>
          )}
          <form onSubmit={handleSubmit}>
            <div className="ss-form-r">
              <div><label className="ui-label">Your name</label><input className="ui-input" name="name" value={form.name} onChange={handleChange} placeholder="Juan dela Cruz" /></div>
              <div><label className="ui-label">Contact</label><input className="ui-input" name="contact" value={form.contact} onChange={handleChange} placeholder="09XX XXX XXXX" /></div>
            </div>
            <div className="ss-form-f"><label className="ui-label ui-label-required">Location</label><input className="ui-input" name="location" required value={form.location} onChange={handleChange} placeholder="Street, Barangay, City" /></div>
            <div className="ss-form-f"><label className="ui-label">Condition</label><select className="ui-select" name="condition" value={form.condition} onChange={handleChange}><option>Injured or sick</option><option>Stray / no owner</option><option>Abandoned</option><option>In immediate danger</option><option>Other</option></select></div>
            <div className="ss-form-f"><label className="ui-label">Details</label><textarea className="ui-textarea" name="details" value={form.details} onChange={handleChange} placeholder="Describe what you see..." /></div>
            <div className="ss-form-f">
              <label className="ui-label">Photo (optional)</label>
              <input className="ui-input" type="file" accept="image/*" onChange={handlePhotoChange} />
              {reportPhoto && <span className="ss-file-label">Selected: {reportPhoto.name}</span>}
            </div>
            <button type="submit" className="ss-btn-primary" disabled={reportState.status === "loading"}>
              {reportState.status === "loading" ? "Sending..." : "Send Report"}
            </button>
          </form>
        </div>
      </section>

      {impact?.top_donors?.length > 0 && (
        <section className="ss-section">
          <div className="ss-section-header">
            <p className="ss-eyebrow-c">Community Impact</p>
            <h2 className="ss-section-title">Our Top Supporters</h2>
            <p className="ss-section-sub">Donors who've gone above and beyond for our rescues.</p>
          </div>
          <div className="ss-donors">
            {impact.top_donors.map((d, i) => (
              <div className="ss-donor-card" key={`${d.name}-${i}`}>
                <div className="ss-donor-rank">#{i + 1}</div>
                <div className="ss-donor-name">{d.name}</div>
                <div className="ss-donor-amt">₱{d.total.toLocaleString()}</div>
              </div>
            ))}
          </div>
        </section>
      )}

      <section className="ss-donate" id="donate">
        <div className="ss-donate-card">
          <p className="ss-eyebrow-c">Support the Mission</p>
          <h2 className="ss-donateT">Every peso feeds, heals, and shelters</h2>
          <p className="ss-donateS">Your donation covers vet care, food, and shelter operations.</p>
          <div className="ss-donateA">
            {donationAmounts.map((a) => (
              <button key={a} className={`ss-amt${amt === a ? " active" : ""}`} onClick={() => setAmt(a)}>{a}</button>
            ))}
          </div>
          <button className="ss-donateB" onClick={() => navigate("/donate", { state: { amount: Number(amt.replace(/[^0-9]/g, "")) } })}>Donate {amt}</button>
        </div>
      </section>

      <footer className="ss-footer" id="about">
        <div className="ss-footM">
          <div className="ss-footB">
            <div className="ss-logo">SECASPI <span>Shelter</span></div>
            <p>Rescuing and rehoming Aspins since 2018. Based in Calamba, Laguna.</p>
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
            <h4>About</h4>
            <ul><li><a href="#">Our Story</a></li><li><a href="#">Contact</a></li><li><a href="#">Privacy</a></li></ul>
          </div>
        </div>
        <div className="ss-footBot">
          <p>© {new Date().getFullYear()} SECASPI Shelter. All rights reserved.</p>
          <p>Calamba, Laguna, Philippines</p>
        </div>
      </footer>
    </div>
  );
}
