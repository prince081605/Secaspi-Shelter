import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ChevronDown } from 'lucide-react';

const styles = `
  .actMenuBtn { display: inline-flex; align-items: center; gap: 4px; }
  .actMenuBtn svg { transition: transform .15s ease; }
  .actMenuBtn[aria-expanded="true"] svg { transform: rotate(180deg); }
  .actMenu {
    position: fixed; z-index: 1000; min-width: 170px; padding: 6px;
    background: var(--surface, #fffdf8); border: 1px solid var(--line); border-radius: 12px;
    box-shadow: 0 16px 32px -16px rgba(43, 36, 32, 0.35);
  }
  .actMenuItem {
    display: block; width: 100%; text-align: left; padding: 8px 10px; border: 0; border-radius: 8px;
    background: none; color: var(--ink); font: inherit; font-size: 0.9rem; cursor: pointer;
  }
  .actMenuItem:hover, .actMenuItem:focus-visible { background: var(--brand-soft, #f3e3d3); outline: none; }
  .actMenuItemDanger { color: #b42318; }
  .actMenuDivider { height: 1px; background: var(--line); margin: 4px 2px; }
`;

const MENU_GAP = 6;
const ITEM_HEIGHT = 37;

/**
 * A compact "Actions ▾" button whose menu replaces a row of buttons. Items are
 * { label, onClick, tone?: 'danger', divider?: true }; a falsy item is skipped, so callers can
 * write `cond && {...}`. The menu is position:fixed so a scrolling table can't clip it, and it
 * closes on selection, outside click, Escape, scroll or resize.
 */
export default function ActionMenu({ items, label = 'Actions', ariaLabel }) {
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState(null);
  const btnRef = useRef(null);
  const menuRef = useRef(null);
  const list = items.filter(Boolean);

  useEffect(() => {
    if (!open) return undefined;
    const close = () => setOpen(false);
    const onDown = (e) => {
      if (!menuRef.current?.contains(e.target) && !btnRef.current?.contains(e.target)) close();
    };
    const onKey = (e) => {
      if (e.key === 'Escape') { close(); btnRef.current?.focus(); }
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
    menuRef.current?.querySelector('button')?.focus();
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('scroll', close, true);
      window.removeEventListener('resize', close);
    };
  }, [open]);

  // Arrow keys move between items, like a native menu.
  const onMenuKey = (e) => {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    e.preventDefault();
    const buttons = [...menuRef.current.querySelectorAll('button')];
    const i = buttons.indexOf(document.activeElement);
    const next = e.key === 'ArrowDown' ? (i + 1) % buttons.length : (i - 1 + buttons.length) % buttons.length;
    buttons[next]?.focus();
  };

  // Placed when opened: under the button and right-aligned to it, or above it when the menu
  // (estimated from its item count) wouldn't fit below.
  const toggle = () => {
    if (open) { setOpen(false); return; }
    const b = btnRef.current.getBoundingClientRect();
    const estHeight = list.reduce((h, it) => h + (it.divider ? 9 : ITEM_HEIGHT), 12);
    const below = b.bottom + MENU_GAP + estHeight <= window.innerHeight;
    setPos({
      // clientWidth, not innerWidth: `right` on a fixed element is measured from the edge of
      // the viewport excluding the scrollbar.
      right: Math.max(8, document.documentElement.clientWidth - b.right),
      ...(below ? { top: b.bottom + MENU_GAP } : { bottom: window.innerHeight - b.top + MENU_GAP }),
    });
    setOpen(true);
  };

  return (
    <>
      <style>{styles}</style>
      <button
        ref={btnRef}
        type="button"
        className="dashBtn actMenuBtn"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={ariaLabel}
        onClick={toggle}
      >
        {label} <ChevronDown size={14} aria-hidden="true" />
      </button>
      {/* Portalled to <body>: a transformed ancestor (e.g. a Reveal animation) would otherwise
          become the containing block for position:fixed and misplace the menu. */}
      {open && createPortal(
        <div
          ref={menuRef}
          className="actMenu"
          role="menu"
          onKeyDown={onMenuKey}
          style={pos || undefined}
        >
          {list.map((item, i) => (item.divider ? (
            <div key={`d${i}`} className="actMenuDivider" role="separator" />
          ) : (
            <button
              key={item.label}
              type="button"
              role="menuitem"
              className={'actMenuItem' + (item.tone === 'danger' ? ' actMenuItemDanger' : '')}
              onClick={() => { setOpen(false); item.onClick(); }}
            >
              {item.label}
            </button>
          )))}
        </div>,
        document.body,
      )}
    </>
  );
}
