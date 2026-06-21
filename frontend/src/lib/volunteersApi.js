import { api } from './api';

export async function adminListVolunteers(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/admin/volunteers${qs ? `?${qs}` : ''}`);
}

export async function adminCreateVolunteer(payload) {
  return api.post('/api/admin/volunteers', payload);
}

export async function adminUpdateVolunteer(id, payload) {
  return api.put(`/api/admin/volunteers/${id}`, payload);
}

export async function adminDeleteVolunteer(id) {
  return api.delete(`/api/admin/volunteers/${id}`);
}

export async function adminCreateVolunteerTask(volunteerId, payload) {
  return api.post(`/api/admin/volunteers/${volunteerId}/tasks`, payload);
}

export async function adminUpdateVolunteerTask(taskId, payload) {
  return api.put(`/api/admin/volunteer-tasks/${taskId}`, payload);
}

export async function adminDeleteVolunteerTask(taskId) {
  return api.delete(`/api/admin/volunteer-tasks/${taskId}`);
}
