import { api } from './api';

// ---- User: volunteer sign-up + self-service ----

export async function submitVolunteerApplication(payload) {
  return api.post('/api/volunteer-applications', payload);
}

export async function listMyVolunteerApplications() {
  return api.get('/api/volunteer-applications');
}

export async function getMyVolunteer() {
  return api.get('/api/volunteer/me');
}

export async function requestVolunteerTask(payload) {
  return api.post('/api/volunteer/tasks', payload);
}

// ---- Admin: volunteer requests ----

export async function adminListVolunteerApplications(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/admin/volunteer-applications${qs ? `?${qs}` : ''}`);
}

export async function adminUpdateVolunteerApplication(id, payload) {
  return api.put(`/api/admin/volunteer-applications/${id}`, payload);
}

export async function adminMarkVolunteerApplicationRead(id) {
  return api.post(`/api/admin/volunteer-applications/${id}/read`);
}

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
