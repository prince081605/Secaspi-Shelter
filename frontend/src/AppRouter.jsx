import { Suspense, lazy, useEffect, useState } from 'react';
import { BrowserRouter, Routes, Route, Navigate, useLocation, useParams } from 'react-router-dom';

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
const Checkout = lazy(() => import('./pages/user/Checkout'));
const NotFound = lazy(() => import('./pages/user/NotFound'));

function RouteFallback() {
  return <div style={{ padding: 24, color: 'var(--muted)' }}>Loading…</div>;
}

// Retrying a declined payment navigates from one /pay/:token to another. Same route, so
// React would keep the component mounted and carry the old checkout's in-flight state
// (and its disabled buttons) into the new one. Keying on the token makes a new payment
// session a genuinely new mount, which is what it is.
function CheckoutRoute() {
  const { token } = useParams();
  return <Checkout key={token} />;
}

function RequireAuth({ children }) {
  const [ok, setOk] = useState(null);
  const { pathname } = useLocation();

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
  // Hand the login page the route that was asked for, so a deep link (an adoption application,
  // a receipt) resumes after logging in instead of landing on the dashboard.
  if (!ok) return <Navigate to="/login" state={{ from: pathname }} replace />;
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
          {/* Visit, Volunteer and Donate are public: the nav offers them to every visitor, so
              the page has to be readable without an account. Each gates its own submit instead
              (see useLoginGate) — you can read and fill the form anonymously, but the write that
              creates the request needs a login. */}
          <Route path="/donate" element={<Donate />} />
          <Route path="/visit" element={<VisitationBooking />} />
          <Route path="/volunteer" element={<VolunteerApply />} />
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
          {/* The simulated payment gateway (AspinPay). A real hosted checkout lives on the
              processor's own domain; this one is a route here, so it still needs a session —
              the backend re-checks that the token's donation belongs to the caller. */}
          <Route
            path="/pay/:token"
            element={
              <RequireAuth>
                <CheckoutRoute />
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
