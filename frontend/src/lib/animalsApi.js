import { api } from './api';

export async function browseAnimals(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/animals${qs ? `?${qs}` : ''}`);
}

export async function getAnimal(id) {
  return api.get(`/api/animals/${id}`);
}

export async function applyForAdoption(animalId, payload) {
  return api.post(`/api/animals/${animalId}/adopt`, payload);
}

export async function applyForFoster(animalId, payload) {
  return api.post(`/api/animals/${animalId}/foster`, payload);
}

export async function listMyAdoptionApplications() {
  return api.get('/api/adoption-applications');
}

export async function listMyFosterApplications() {
  return api.get('/api/foster-applications');
}

// ---- Admin: animal management (Phase 6) ----

export async function adminGetAnimal(id) {
  return api.get(`/api/admin/animals/${id}`);
}

export async function adminListAnimals(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/admin/animals${qs ? `?${qs}` : ''}`);
}

export async function adminCreateAnimal(formData) {
  return api.post('/api/animals', formData);
}

export async function adminUpdateAnimal(id, payload) {
  return api.put(`/api/animals/${id}`, payload);
}

export async function adminArchiveAnimal(id) {
  return api.post(`/api/animals/${id}/archive`);
}

export async function adminDeleteAnimal(id) {
  return api.delete(`/api/animals/${id}`);
}

export async function adminAddAnimalPhotos(id, formData) {
  return api.post(`/api/animals/${id}/photos`, formData);
}

export async function adminDeleteAnimalPhoto(animalId, photoId) {
  return api.delete(`/api/animals/${animalId}/photos/${photoId}`);
}

// ---- Admin: adoption workflow (Phase 6) ----

export async function adminListAdoptionApplications(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/admin/adoption-applications${qs ? `?${qs}` : ''}`);
}

export async function adminUpdateAdoptionApplication(id, payload) {
  return api.put(`/api/admin/adoption-applications/${id}`, payload);
}

export async function adminMarkAdoptionApplicationRead(id) {
  return api.post(`/api/admin/adoption-applications/${id}/read`);
}

// ---- Admin: foster workflow (Phase 6) ----

export async function adminListFosterApplications(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/admin/foster-applications${qs ? `?${qs}` : ''}`);
}

export async function adminUpdateFosterApplication(id, payload) {
  return api.put(`/api/admin/foster-applications/${id}`, payload);
}

// ---- Admin: medical records & vaccinations (Phase 6) ----

export async function adminCreateMedicalRecord(animalId, payload) {
  return api.post(`/api/animals/${animalId}/medical-records`, payload);
}

export async function adminUpdateMedicalRecord(id, payload) {
  return api.put(`/api/medical-records/${id}`, payload);
}

export async function adminDeleteMedicalRecord(id) {
  return api.delete(`/api/medical-records/${id}`);
}

export async function adminCreateVaccination(animalId, payload) {
  return api.post(`/api/animals/${animalId}/vaccinations`, payload);
}

export async function adminUpdateVaccination(id, payload) {
  return api.put(`/api/vaccinations/${id}`, payload);
}

export async function adminDeleteVaccination(id) {
  return api.delete(`/api/vaccinations/${id}`);
}
