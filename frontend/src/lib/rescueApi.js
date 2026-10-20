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

export async function adminMarkRescueReportRead(id) {
  return api.post(`/api/rescue-reports/${id}/read`);
}

// ---- System Admin (Security): reporters who send fake reports ----

export async function adminListFlaggedReporters() {
  return api.get('/api/admin/security/flagged-reporters');
}

export async function adminBlockReporter(reportId, reason) {
  return api.post(`/api/rescue-reports/${reportId}/block`, { reason });
}

export async function adminListBlockedReporters() {
  return api.get('/api/admin/blocked-reporters');
}

export async function adminUnblockReporter(id) {
  return api.delete(`/api/admin/blocked-reporters/${id}`);
}
