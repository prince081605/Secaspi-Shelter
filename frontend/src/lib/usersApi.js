import { api } from './api';

export async function adminListUsers(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/admin/users${qs ? `?${qs}` : ''}`);
}

// The System Admin's home: accounts by role and status.
export async function adminGetUserSummary() {
  return api.get('/api/admin/users/summary');
}

export async function adminUpdateUser(id, payload) {
  return api.put(`/api/admin/users/${id}`, payload);
}
