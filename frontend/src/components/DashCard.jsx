/**
 * Mobile card for admin lists. On phones each table row is rendered as one of these instead of a
 * `<tr>`, so records stack into readable cards that fit the screen (desktop keeps the table).
 *
 * - `media`     optional leading node (e.g. an animal photo)
 * - `title` / `subtitle`  card header text
 * - `fields`    array of `{ label, value }` (value may be any node — badges, links, inputs);
 *               falsy entries are skipped so callers can conditionally include a field
 * - `actions`   node rendered in the footer (reuses the same buttons as the table row)
 * - `accent`    'unread' | 'overdue' — colored left border
 */
export default function DashCard({ media, title, subtitle, fields = [], actions, accent, className = '' }) {
  const shownFields = fields.filter(Boolean);
  return (
    <div className={`dashCardItem ${accent ? 'dashCardItem--' + accent : ''} ${className}`.trim()}>
      {(media || title || subtitle) && (
        <div className="dashCardHead">
          {media}
          <div className="dashCardHeadText">
            {title != null && <div className="dashCardTitle">{title}</div>}
            {subtitle != null && subtitle !== '' && <div className="dashCardSub">{subtitle}</div>}
          </div>
        </div>
      )}
      {shownFields.length > 0 && (
        <dl className="dashCardFields">
          {shownFields.map((f, i) => (
            <div key={i}>
              <dt>{f.label}</dt>
              <dd>{f.value}</dd>
            </div>
          ))}
        </dl>
      )}
      {actions && <div className="dashCardActions">{actions}</div>}
    </div>
  );
}
