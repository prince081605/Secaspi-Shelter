import { api } from './api';

// ---- In-shelter locations (House 1, Kennel 2, ...) ----
// Staff can read the list and move animals; only admins add, rename or remove areas.

export async function listShelterLocations() {
  return api.get('/api/admin/shelter-locations');
}

export async function createShelterLocation(name) {
  return api.post('/api/admin/shelter-locations', { name });
}

export async function renameShelterLocation(id, name) {
  return api.put(`/api/admin/shelter-locations/${id}`, { name });
}

export async function deleteShelterLocation(id) {
  return api.delete(`/api/admin/shelter-locations/${id}`);
}

/**
 * Move an animal to another area (locationId null = unassigned). `source` records how it was
 * done: 'qr_page' from the animal's page (what its QR code opens), 'admin' from the Animals panel.
 */
export async function moveAnimal(animalId, { locationId, note, source }) {
  return api.post(`/api/animals/${animalId}/location`, { location_id: locationId, note: note || null, source });
}
