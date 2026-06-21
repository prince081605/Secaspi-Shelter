const STATUS_VARIANTS = {
  // positive / completed
  approved: 'badgeGreen',
  completed: 'badgeGreen',
  verified: 'badgeGreen',
  resolved: 'badgeGreen',
  converted: 'badgeGreen',
  active: 'badgeGreen',
  available: 'badgeGreen',
  adopted: 'badgeGreen',
  // in progress / waiting
  pending: 'badgeSky',
  scheduled: 'badgeSky',
  not_scheduled: 'badgeSky',
  assigned: 'badgeSky',
  ongoing: 'badgeSky',
  under_assessment: 'badgeSky',
  in_progress: 'badgeSky',
  fostered: 'badgeSky',
  // needs attention / negative
  rejected: 'badgeOrange',
  declined: 'badgeOrange',
  suspended: 'badgeOrange',
  medical: 'badgeOrange',
  quarantine: 'badgeOrange',
  archived: 'badgeOrange',
};

export default function StatusBadge({ status, fallback = 'N/A' }) {
  const key = String(status || '').toLowerCase();
  const variant = STATUS_VARIANTS[key];
  const cls = variant ? `badge ${variant}` : 'badge';
  const label = status ? String(status).replace(/_/g, ' ') : fallback;
  return <span className={cls}>{label}</span>;
}
