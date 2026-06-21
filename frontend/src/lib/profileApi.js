import { api } from './api';

export async function updateProfile(payload) {
  return api.put('/api/profile', payload);
}

export async function changePassword(payload) {
  return api.post('/api/profile/change-password', payload);
}
