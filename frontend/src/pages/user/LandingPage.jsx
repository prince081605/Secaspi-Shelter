import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { getFeaturedAnimals, getImpactStats } from "../../lib/publicHomeApi.js";
import { createReport } from "../../lib/rescueApi.js";
import { auth } from "../../lib/auth.js";
import { getPublicSettings, settingImageUrl } from "../../lib/settingsApi.js";
import {
  Navbar,
  Hero,
  Pathways,
  FeaturedAnimals,
  ImpactBand,
  Process,
  RescueForm,
  TopSupporters,
  DonateCta,
  SiteFooter,
  donationAmounts,
} from "./LandingSections.jsx";
import "./LandingPage.css";

const animalsFallback = [
  { id: 1, name: "Bingo", age: "2 yrs", species: "Dog", status: "Available for adoption", photo: null },
  { id: 2, name: "Maya", age: "1 yr", species: "Dog", status: "Available for adoption", photo: null },
  { id: 3, name: "Boss", age: "3 yrs", species: "Dog", status: "Available for adoption", photo: null },
  { id: 4, name: "Luna", age: "6 mos", species: "Dog", status: "Available for adoption", photo: null },
];

const URGENCY_BY_CONDITION = {
  "Injured or sick": "high",
  "Stray / no owner": "low",
  "Abandoned": "medium",
  "In immediate danger": "critical",
  "Other": "medium",
};

export default function LandingPage() {
  const navigate = useNavigate();
  const [amt, setAmt] = useState(donationAmounts[1]);
  const [animals, setAnimals] = useState(animalsFallback);
  const [impact, setImpact] = useState(null);
  const [settings, setSettings] = useState({});
  const [menuOpen, setMenuOpen] = useState(false);
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [form, setForm] = useState({ name: "", contact: "", location: "", condition: "Injured or sick", details: "", latitude: null, longitude: null });
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
      if (form.latitude != null && form.longitude != null) {
        fd.append("latitude", form.latitude);
        fd.append("longitude", form.longitude);
      }
      if (reportPhoto) fd.append("photo", reportPhoto);

      await createReport(fd);

      setReportState({ status: "success", error: "" });
      setForm({ name: "", contact: "", location: "", condition: "Injured or sick", details: "", latitude: null, longitude: null });
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
          onPinLocation={(lat, lng) => setForm((f) => ({ ...f, latitude: lat, longitude: lng }))}
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
