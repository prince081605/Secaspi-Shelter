// Today in the viewer's own timezone, as YYYY-MM-DD for a date input's `min`/`max`.
// (toISOString() would give the UTC day, which is still "yesterday" before 8 AM in Manila.)
export function todayLocal() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
