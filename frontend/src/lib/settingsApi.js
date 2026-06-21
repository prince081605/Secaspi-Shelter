import { api } from './api';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '';

export async function getPublicSettings() {
  return api.get('/api/home/settings');
}

export async function adminGetSettings() {
  return api.get('/api/admin/settings');
}

export async function adminUpdateSettings(payload) {
  return api.put('/api/admin/settings', payload);
}

export async function adminUploadSettingImage(key, file) {
  const fd = new FormData();
  fd.append('key', key);
  fd.append('image', file);
  return api.post('/api/admin/settings/image', fd);
}

export function settingImageUrl(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${API_BASE_URL}/storage/${path}`;
}
