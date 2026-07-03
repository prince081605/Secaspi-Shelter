import { useEffect, useState } from 'react';

/**
 * True when the viewport is at or below `bp` px. Used to swap admin tables for card lists on
 * phones. Initialized from matchMedia in the state initializer and only updated inside the
 * `change` handler (never synchronously in the effect body) so it doesn't trip the
 * react-hooks/set-state-in-effect rule.
 */
export default function useIsMobile(bp = 560) {
  const query = `(max-width:${bp}px)`;
  const [isMobile, setIsMobile] = useState(() => window.matchMedia(query).matches);

  useEffect(() => {
    const mq = window.matchMedia(query);
    const handler = (e) => setIsMobile(e.matches);
    mq.addEventListener('change', handler);
    return () => mq.removeEventListener('change', handler);
  }, [query]);

  return isMobile;
}
