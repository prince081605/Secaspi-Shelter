/**
 * Shared admin-table pager. Renders nothing for single-page result sets.
 *
 * Pass the Laravel paginator meta ({ current_page, last_page }) and an onPage(pageNumber)
 * callback (typically the setPage state setter). Used by the admin list panels so every
 * table reaches every record instead of silently capping at page 1.
 */
export default function Pagination({ meta, onPage }) {
  const current = meta?.current_page || 1;
  const last = meta?.last_page || 1;
  if (last <= 1) return null;

  return (
    <div className="dashPagination">
      <button
        className="dashBtn"
        disabled={current <= 1}
        onClick={() => onPage(Math.max(1, current - 1))}
      >
        ← Prev
      </button>
      <span className="dashPageLabel">Page {current} of {last}</span>
      <button
        className="dashBtn"
        disabled={current >= last}
        onClick={() => onPage(Math.min(last, current + 1))}
      >
        Next →
      </button>
    </div>
  );
}
