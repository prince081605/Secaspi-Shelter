import { api } from './api';

// Post-adoption care: check-ins, adopter updates (and Happy Tails stories), return requests.

// How the animal is doing — in the adopter's words, and on the scale staff record.
export const ADOPTER_WELLBEING = [
  { value: 'doing_well', label: 'Doing great' },
  { value: 'some_concerns', label: 'Mostly good, a few concerns' },
  { value: 'need_help', label: 'We need help' },
];
export const STAFF_WELLBEING = [
  { value: 'doing_well', label: 'Doing well' },
  { value: 'needs_support', label: 'Needs support' },
  { value: 'concern', label: 'Concern' },
];
export const CONTACT_METHODS = [
  { value: 'call', label: 'Phone call' },
  { value: 'text', label: 'Text / chat' },
  { value: 'visit', label: 'Home visit' },
  { value: 'message', label: 'Message on the website' },
  { value: 'adopter_update', label: 'Adopter sent an update' },
  { value: 'other', label: 'Other' },
];
// Where a returned animal goes.
export const RETURN_DESTINATIONS = [
  { value: 'available', label: 'Back on the adoption listing' },
  { value: 'medical', label: 'Medical care first' },
  { value: 'quarantine', label: 'Quarantine first' },
];
export const MAX_UPDATE_PHOTOS = 4;

export const labelOf = (list, value) => list.find((o) => o.value === value)?.label || value || '';

// ---- The adopter ("My Adopted Pets") ----

export const getMyAdoptions = () => api.get('/api/my-adoptions');

export function sendAdoptionUpdate(applicationId, { wellbeing, message, photos, sharePublicly }) {
  const fd = new FormData();
  fd.append('wellbeing', wellbeing);
  fd.append('message', message);
  fd.append('share_publicly', sharePublicly ? '1' : '0');
  (photos || []).forEach((file) => fd.append('photos[]', file));
  return api.post(`/api/my-adoptions/${applicationId}/updates`, fd);
}

export const stopSharingUpdate = (applicationId, updateId) =>
  api.post(`/api/my-adoptions/${applicationId}/updates/${updateId}/stop-sharing`);

export const requestAdoptionReturn = (applicationId, reason) =>
  api.post(`/api/my-adoptions/${applicationId}/return`, { reason });

// ---- Public ----

export const getHappyTails = () => api.get('/api/home/happy-tails');

// ---- Staff (Post-adoption page) ----

export const adminPostAdoptionSummary = () => api.get('/api/admin/post-adoption/summary');
export const adminListCheckIns = (view, page = 1) => api.get(`/api/admin/post-adoption/check-ins?view=${view}&page=${page}`);
export const adminRecordCheckIn = (id, payload) => api.put(`/api/admin/post-adoption/check-ins/${id}`, payload);
export const adminListAdoptionUpdates = (filter, page = 1) => api.get(`/api/admin/post-adoption/updates?filter=${filter}&page=${page}`);
export const adminMarkAdoptionUpdateRead = (id) => api.post(`/api/admin/post-adoption/updates/${id}/read`);
export const adminReviewStory = (id, decision) => api.put(`/api/admin/post-adoption/updates/${id}/story`, { decision });
export const adminListReturns = (page = 1) => api.get(`/api/admin/post-adoption/returns?page=${page}`);
export const adminResolveReturn = (id, payload) => api.put(`/api/admin/post-adoption/returns/${id}`, payload);
