import { api } from './api';

export async function adminListIntakes(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/admin/intakes${qs ? `?${qs}` : ''}`);
}

export async function adminGetIntake(id) {
  return api.get(`/api/admin/intakes/${id}`);
}

export async function adminCreateIntake(formData) {
  return api.post('/api/admin/intakes', formData);
}

export async function adminUpdateIntake(id, payload) {
  return api.put(`/api/admin/intakes/${id}`, payload);
}

export async function adminDeleteIntake(id) {
  return api.delete(`/api/admin/intakes/${id}`);
}

export async function adminConvertIntake(id) {
  return api.post(`/api/admin/intakes/${id}/convert`);
}

export async function adminAddIntakeDocuments(id, formData) {
  return api.post(`/api/admin/intakes/${id}/documents`, formData);
}

export async function adminDeleteIntakeDocument(intakeId, documentId) {
  return api.delete(`/api/admin/intakes/${intakeId}/documents/${documentId}`);
}
