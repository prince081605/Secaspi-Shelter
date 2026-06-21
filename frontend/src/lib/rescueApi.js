import { api } from './api';

export async function createReport(formData) {
  return api.post('/api/rescue-reports', formData);
}

// ---- Admin: rescue report triage (Phase 6) ----

export async function adminListRescueReports(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/rescue-reports${qs ? `?${qs}` : ''}`);
}

export async function adminUpdateRescueReport(id, payload) {
  return api.post(`/api/rescue-reports/${id}/status`, payload);
}
