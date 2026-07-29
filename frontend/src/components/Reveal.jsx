import useInView from '../lib/useInView';

/**
 * Wraps a block so it fades and slides in when scrolled into view.
 *
 *   <Reveal className="ui-card">…</Reveal>                  slide up (default)
 *   <Reveal variant="left" className="ui-card">…</Reveal>   slide in from the left
 *   <Reveal delayIndex={2}>…</Reveal>                       staggered 200ms behind the first
 *   <Reveal variant="group">…</Reveal>                      one observer for a whole grid;
 *                                                           children take `ui-reveal-item`
 *                                                           and their own `--i`
 *
 * Each instance owns its own observer, so a page can use as many as it likes without the
 * rules-of-hooks problem you'd hit calling useInView in a loop. For a list rendered by
 * `.map()`, prefer one `ui-reveal-group` container with `ui-reveal-item` children and a
 * `--i` per child — that's a single observer for the whole list rather than one each.
 *
 * `as` lets the wrapper be the real element (section/article/li) instead of adding a
 * div, which matters where a parent grid or flex layout expects direct children.
 */
export default function Reveal({
  as: Tag = 'div',
  variant = 'up',
  delayIndex = 0,
  className = '',
  style,
  children,
  ...rest
}) {
  const [ref, inView] = useInView();
  const variantClass =
    variant === 'group' ? 'ui-reveal-group'
      : variant === 'left' ? 'ui-reveal-left'
        : variant === 'right' ? 'ui-reveal-right'
          : 'ui-reveal';

  return (
    <Tag
      ref={ref}
      className={`${variantClass}${inView ? ' is-visible' : ''} ${className}`.trim()}
      style={{ '--i': delayIndex, ...style }}
      {...rest}
    >
      {children}
    </Tag>
  );
}
