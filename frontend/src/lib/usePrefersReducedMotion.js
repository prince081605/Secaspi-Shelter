import { useEffect, useState } from 'react';

/**
 * True when the visitor has asked their system to reduce motion.
 *
 * CSS handles most of this already (see the reduced-motion block in LandingPage.css), but
 * anything driven by a timer has to opt out in JavaScript: with transitions disabled, a
 * carousel that keeps advancing every few seconds becomes a hard cut rather than a fade —
 * more jarring than the animation it was meant to spare them, and it moves the page while
 * they are reading it.
 *
 * Same shape as useIsMobile: read once in the state initializer, then only update from the
 * `change` handler, so it doesn't trip react-hooks/set-state-in-effect.
 */
export default function usePrefersReducedMotion() {
  const query = '(prefers-reduced-motion: reduce)';
  const [reduced, setReduced] = useState(() => window.matchMedia(query).matches);

  useEffect(() => {
    const mq = window.matchMedia(query);
    const handler = (e) => setReduced(e.matches);
    mq.addEventListener('change', handler);
    return () => mq.removeEventListener('change', handler);
  }, [query]);

  return reduced;
}
