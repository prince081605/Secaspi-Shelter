import { api } from './api';

export async function createDonation(formData) {
  return api.post('/api/donations', formData);
}

export async function listDonations(page = 1) {
  return api.get(`/api/donations?page=${page}`);
}

export async function getDonation(id) {
  return api.get(`/api/donations/${id}`);
}

// ---- Admin: donation management (Phase 6) ----

export async function adminListDonations(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/admin/donations${qs ? `?${qs}` : ''}`);
}

export async function adminGetDonationStats() {
  return api.get('/api/admin/donations/stats');
}

export async function adminVerifyDonation(id, status) {
  return api.post(`/api/donations/${id}/verify`, { status });
}
