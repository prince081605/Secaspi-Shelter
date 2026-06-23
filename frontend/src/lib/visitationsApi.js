import { api } from './api';

// ---- User: visit scheduling ----

export async function createVisitation(payload) {
  return api.post('/api/visitations', payload);
}

export async function listVisitations() {
  return api.get('/api/visitations');
}

// ---- Admin: visit request management ----

export async function adminListVisitations(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/admin/visitations${qs ? `?${qs}` : ''}`);
}

export async function adminUpdateVisitation(id, payload) {
  return api.put(`/api/admin/visitations/${id}`, payload);
}

export async function adminMarkVisitationRead(id) {
  return api.post(`/api/admin/visitations/${id}/read`);
}
