import { useEffect, useState } from 'react';
import { api } from './api';

// The admin-managed lists — dog_breed, cat_breed, behavioral_issue, medical_record_type,
// valid_id_type — as { value, label, hidden } options. Dropdowns offer the visible ones (see
// visibleOptions); showing an existing record's value uses every option (see optionLabel), since
// a record can hold an option that has since been hidden.

let cache = null;
let inflight = null;

/** Every list, fetched once and shared until an admin changes one. */
export function loadCategories() {
  if (cache) return Promise.resolve(cache);
  inflight ??= api.get('/api/categories')
    .then((data) => {
      cache = data?.categories || {};
      return cache;
    })
    .finally(() => { inflight = null; });
  return inflight;
}

/** Forget the cached lists, so the next form to open picks up an admin's change. */
export function invalidateCategories() {
  cache = null;
}

/** The lists for a component, keyed by type. Empty until loaded (or if loading fails). */
export function useCategories() {
  const [lists, setLists] = useState(cache);
  useEffect(() => {
    if (lists) return undefined;
    let alive = true;
    loadCategories()
      .then((loaded) => { if (alive) setLists(loaded); })
      .catch(() => { if (alive) setLists({}); });
    return () => { alive = false; };
  }, [lists]);
  return lists || {};
}

export const visibleOptions = (options) => (options || []).filter((o) => !o.hidden);

/** The label for a stored value; the value itself when it isn't on the list. */
export const optionLabel = (options, value) => (options || []).find((o) => o.value === value)?.label || value || '';

// ---- Admin: managing the lists (Categories page) ----

const changed = (promise) => promise.then((result) => {
  invalidateCategories();
  return result;
});

export const adminGetCategory = (type) => api.get(`/api/admin/categories/${type}`);
export const adminAddCategoryOption = (type, label) => changed(api.post(`/api/admin/categories/${type}`, { label }));
export const adminRenameCategoryOption = (type, id, label) => changed(api.put(`/api/admin/categories/${type}/${id}`, { label }));
export const adminSetCategoryOptionHidden = (type, id, hidden) => changed(api.put(`/api/admin/categories/${type}/${id}`, { is_active: !hidden }));
export const adminDeleteCategoryOption = (type, id) => changed(api.delete(`/api/admin/categories/${type}/${id}`));
