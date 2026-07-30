import { useEffect, useState } from 'react';
import { NavLink, useLocation, useNavigate } from 'react-router-dom';
import { ArrowLeft, PawPrint } from 'lucide-react';
import { getAuthToken } from '../lib/api';

/**
 * The one site navigation: the landing page's floating pill, unchanged, on every public page.
 *
 * The landing nav is the default state — transparent at rest, condensing into a bordered,
 * blurred pill once the page scrolls, so the nav earns its background only when there is
 * content behind it. Navigating to another page keeps exactly that bar; the only difference
 * is that the link for the page you are on is highlighted in terracotta.
 *
 * NavLink handles the matching, and because it matches nested paths by default, /adopt/50
 * still marks "Adopt" as current. On the landing page no link is current — the logo is home.
 *
 * `back` optionally renders a back button beside the actions, for pages that sit inside a
 * flow (an animal detail page inside the adoption list) where "up one level" is a distinct
 * action from the nav links. Unlike the other actions it survives the mobile breakpoint,
 * since it is the page's own escape hatch rather than a site-wide destination.
 *
 * `shelterName` overrides the wordmark for callers that have already loaded site settings;
 * the nav does not fetch them itself, since it renders on every page and the name is a
 * label, not something worth an API call per navigation.
 */

// The landing page's five, in its order. Matchmaker is deliberately not here — the landing
// nav never carried it, and the Adopt page surfaces it with a button of its own.
const LINKS = [
  { to: '/adopt', label: 'Adopt' },
  { to: '/visit', label: 'Visit' },
  { to: '/volunteer', label: 'Volunteer' },
  { to: '/transparency', label: 'Transparency' },
  { to: '/donate', label: 'Donate' },
];

export default function SiteNav({ back, shelterName = 'SECASPI Shelter' }) {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const [scrolled, setScrolled] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);

  // Read the stored token rather than calling /api/user: this nav renders on every public
  // page, and an auth request per page load would be a lot of traffic for a label. A stale
  // token only mis-labels the button — the dashboard route still gates properly.
  const isLoggedIn = Boolean(getAuthToken());

  // The wordmark accents everything after the first word ("SECASPI Shelter"), so a renamed
  // shelter keeps the same two-tone treatment instead of hardcoding the default's split.
  const [firstWord, ...restWords] = shelterName.trim().split(/\s+/);
  const rest = restWords.join(' ');

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  // Close the menu on navigation, and lock body scroll while it is open.
  useEffect(() => {
    document.body.style.overflow = menuOpen ? 'hidden' : '';
    return () => { document.body.style.overflow = ''; };
  }, [menuOpen]);

  const linkClass = ({ isActive }) => (isActive ? 'is-current' : undefined);

  // The rescue form lives in a section of the landing page, so this is a scroll when we are
  // already there and a route change with a hash when we are not (LandingPage honours it).
  const reportStray = () => {
    setMenuOpen(false);
    if (pathname === '/') document.getElementById('report')?.scrollIntoView({ behavior: 'smooth' });
    else navigate('/#report');
  };

  return (
    <header className={`site-header${scrolled ? ' scrolled' : ''}${back ? ' has-back' : ''}`}>
      <nav className="site-nav" aria-label="Primary">
        <NavLink to="/" className="site-logo">
          <span className="site-logo-mark" aria-hidden="true"><PawPrint size={20} /></span>
          {firstWord} {rest && <span className="site-logo-accent">{rest}</span>}
        </NavLink>

        <ul className="site-nav-links">
          {LINKS.map((l) => (
            <li key={l.to}>
              <NavLink to={l.to} className={linkClass}>{l.label}</NavLink>
            </li>
          ))}
        </ul>

        <div className="site-nav-actions">
          {back && (
            <button className="site-nav-ghost site-nav-back" onClick={() => navigate(back.to)}>
              <ArrowLeft size={16} aria-hidden="true" />
              {back.label}
            </button>
          )}
          <button
            className="site-nav-ghost"
            onClick={() => navigate(isLoggedIn ? '/dashboard' : '/login')}
          >
            {isLoggedIn ? 'Dashboard' : 'Login'}
          </button>
          <button className="site-nav-cta" onClick={reportStray}>Report a stray</button>
          <button
            className={`site-hamburger${menuOpen ? ' open' : ''}`}
            onClick={() => setMenuOpen((v) => !v)}
            aria-label="Toggle menu"
            aria-expanded={menuOpen}
          >
            <span></span><span></span><span></span>
          </button>
        </div>
      </nav>

      <div className={`site-mobile-menu${menuOpen ? ' open' : ''}`}>
        {LINKS.map((l) => (
          <NavLink key={l.to} to={l.to} className={linkClass} onClick={() => setMenuOpen(false)}>
            {l.label}
          </NavLink>
        ))}
        <button className="site-mobile-link" onClick={reportStray}>Report a stray</button>
        <button
          className="site-mobile-link"
          onClick={() => { setMenuOpen(false); navigate(isLoggedIn ? '/dashboard' : '/login'); }}
        >
          {isLoggedIn ? 'Dashboard' : 'Login'}
        </button>
      </div>
    </header>
  );
}
