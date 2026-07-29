import { useCallback, useEffect, useState } from 'react';

/**
 * Fires once an element scrolls into view, used to trigger the fade/slide entrance CSS
 * (`.ui-reveal*` in styles/theme.css, `.lp-reveal*` on the landing page).
 *
 * Returns `[ref, inView]` — spread the ref onto the element and append `is-visible` to its
 * className when `inView` is true.
 *
 * `ref` is a CALLBACK ref backed by state, not a useRef box, and that is load-bearing. With
 * a useRef the setup effect runs once with `ref.current === null` for any element that isn't
 * in the DOM on that first pass — a section that returns null until its data loads, say —
 * and since the deps never change afterwards the effect never re-runs, so the observer is
 * never attached and the content stays at `opacity: 0` forever. TopSupporters in
 * LandingSections.jsx does exactly this (`if (!topDonors?.length) return null`) and rendered
 * as a permanently blank section because of it. A callback ref re-runs setup when the node
 * actually attaches.
 *
 * Because the reveal CSS starts at `opacity: 0`, anything that stops this hook from
 * reporting leaves content invisible — far worse than a missing animation. Visibility is
 * therefore detected two independent ways:
 *
 *   1. IntersectionObserver — the efficient path, no work on the scroll thread.
 *   2. A geometry check on scroll/resize — the guarantee. Some environments create an
 *      observer, deliver its initial entry, then never report again as the page scrolls,
 *      which silently disables an observer-only implementation below the first fold.
 *
 * Whichever notices first wins; both are then torn down.
 */
export default function useInView({ threshold = 0.15, once = true, rootMargin = '0px' } = {}) {
  const [node, setNode] = useState(null);
  const [inView, setInView] = useState(false);

  const ref = useCallback((el) => setNode(el), []);

  useEffect(() => {
    if (!node) return undefined;

    let revealed = false;
    let observer;

    // Fraction of the element on screen, clamped against the viewport so an element taller
    // than the screen can still satisfy the threshold (it can never be 100% visible).
    const onScreen = () => {
      const r = node.getBoundingClientRect();
      if (r.height === 0) return false;
      const vh = window.innerHeight || document.documentElement.clientHeight;
      const shown = Math.min(r.bottom, vh) - Math.max(r.top, 0);
      return shown > 0 && shown / Math.min(r.height, vh) >= threshold;
    };

    const teardown = () => {
      window.removeEventListener('scroll', check);
      window.removeEventListener('resize', check);
      if (observer) observer.disconnect();
    };

    const reveal = () => {
      if (revealed) return;
      revealed = true;
      setInView(true);
      if (once) teardown();
    };

    function check() {
      if (onScreen()) reveal();
      else if (!once && revealed) {
        revealed = false;
        setInView(false);
      }
    }

    if (typeof IntersectionObserver !== 'undefined') {
      observer = new IntersectionObserver(
        ([entry]) => {
          if (entry.isIntersecting) reveal();
          else if (!once) check();
        },
        { threshold, rootMargin }
      );
      observer.observe(node);
    }

    window.addEventListener('scroll', check, { passive: true });
    window.addEventListener('resize', check, { passive: true });

    // Deferred rather than called inline: a synchronous setState in an effect body trips
    // react-hooks/set-state-in-effect and causes a cascading render.
    const initial = setTimeout(check, 0);

    return () => {
      clearTimeout(initial);
      teardown();
    };
  }, [node, threshold, once, rootMargin]);

  /* Separate concern: the element has been revealed but the entrance never plays. Some
     environments apply an animation/transition and then never advance it, holding the
     element at frame 0 — opacity 0 — so the class is right, the CSS is right, and the
     section still renders blank.

     `.reveal-settled` (styles/theme.css) hard-stops both channels and forces the finished
     state. The delay must exceed the longest possible entrance — 1.5s duration plus the
     capped 0.8s stagger — so a real animation always finishes first and this is a silent
     no-op. It only has a visible effect when the entrance genuinely failed to run. */
  useEffect(() => {
    if (!inView || !node) return undefined;
    const settle = setTimeout(() => node.classList.add('reveal-settled'), 2500);
    return () => clearTimeout(settle);
  }, [inView, node]);

  return [ref, inView];
}
