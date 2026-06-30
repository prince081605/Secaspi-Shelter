import { Suspense, lazy, useEffect, useState } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';

import { auth } from './lib/auth';
import AiAssistant from './components/AiAssistant';

// Route components are lazy-loaded so each visitor only downloads the chunk for the page they're
// actually on. Previously every page was imported eagerly, so the landing page shipped the whole
// dashboard, every admin panel, Leaflet (rescue map) and Recharts upfront (audit §2 D-1 / §11 C-2).
const Login = lazy(() => import('./pages/user/Login'));
const Register = lazy(() => import('./pages/user/Register'));
const ForgotPassword = lazy(() => import('./pages/user/ForgotPassword'));
const ResetPassword = lazy(() => import('./pages/user/ResetPassword'));
const Home = lazy(() => import('./pages/user/Home'));
const Adoption = lazy(() => import('./pages/user/Adoption'));
const Matchmaker = lazy(() => import('./pages/user/Matchmaker'));
const AnimalDetail = lazy(() => import('./pages/user/AnimalDetail'));
const AdoptionApply = lazy(() => import('./pages/user/AdoptionApply'));
const FosterApply = lazy(() => import('./pages/user/FosterApply'));
const Dashboard = lazy(() => import('./pages/user/Dashboard'));
const Donate = lazy(() => import('./pages/user/Donate'));
const Transparency = lazy(() => import('./pages/user/Transparency'));
const VisitationBooking = lazy(() => import('./pages/user/VisitationBooking'));
const VolunteerApply = lazy(() => import('./pages/user/VolunteerApply'));
const DonationHistory = lazy(() => import('./pages/user/DonationHistory'));
const Receipt = lazy(() => import('./pages/user/Receipt'));
const NotFound = lazy(() => import('./pages/user/NotFound'));

function RouteFallback() {
  return <div style={{ padding: 24, color: 'var(--muted)' }}>Loading…</div>;
}

function RequireAuth({ children }) {
  const [ok, setOk] = useState(null);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        await auth.me();
        if (mounted) setOk(true);
      } catch {
        if (mounted) setOk(false);
      }
    })();

    return () => {
      mounted = false;
    };
  }, []);

  if (ok === null) return <div style={{ padding: 24 }}>Checking session...</div>;
  if (!ok) return <Navigate to="/login" replace />;
  return children;
}

export default function AppRouter() {
  return (
    <BrowserRouter>
      <Suspense fallback={<RouteFallback />}>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/reset-password" element={<ResetPassword />} />
          <Route path="/" element={<Home />} />
          <Route path="/adopt" element={<Adoption />} />
          <Route path="/matchmaker" element={<Matchmaker />} />
          <Route path="/transparency" element={<Transparency />} />
          <Route path="/adopt/:id" element={<AnimalDetail />} />
          <Route
            path="/adopt/:id/apply"
            element={
              <RequireAuth>
                <AdoptionApply />
              </RequireAuth>
            }
          />
          <Route
            path="/adopt/:id/foster"
            element={
              <RequireAuth>
                <FosterApply />
              </RequireAuth>
            }
          />
          <Route
            path="/dashboard"
            element={
              <RequireAuth>
                <Dashboard />
              </RequireAuth>
            }
          />
          <Route
            path="/donate"
            element={
              <RequireAuth>
                <Donate />
              </RequireAuth>
            }
          />
          <Route
            path="/visit"
            element={
              <RequireAuth>
                <VisitationBooking />
              </RequireAuth>
            }
          />
          <Route
            path="/volunteer"
            element={
              <RequireAuth>
                <VolunteerApply />
              </RequireAuth>
            }
          />
          <Route
            path="/donations"
            element={
              <RequireAuth>
                <DonationHistory />
              </RequireAuth>
            }
          />
          <Route
            path="/donations/:id"
            element={
              <RequireAuth>
                <Receipt />
              </RequireAuth>
            }
          />
          <Route path="*" element={<NotFound />} />
        </Routes>
      </Suspense>
      <AiAssistant />
    </BrowserRouter>
  );
}
