import { useEffect, useState } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';

import Login from './pages/Login';
import Register from './pages/Register';
import ForgotPassword from './pages/ForgotPassword';
import Home from './pages/Home';
import Adoption from './pages/Adoption';
import AnimalDetail from './pages/AnimalDetail';
import AdoptionApply from './pages/AdoptionApply';
import FosterApply from './pages/FosterApply';
import Dashboard from './pages/Dashboard';
import Donate from './pages/Donate';
import DonationHistory from './pages/DonationHistory';
import Receipt from './pages/Receipt';
import NotFound from './pages/NotFound';
import { auth } from './lib/auth';

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
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/" element={<Home />} />
        <Route path="/adopt" element={<Adoption />} />
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
    </BrowserRouter>
  );
}

