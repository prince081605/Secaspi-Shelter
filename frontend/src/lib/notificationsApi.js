import { api } from './api';

export async function listNotifications() {
  return api.get('/api/notifications');
}

export async function markNotificationRead(id) {
  return api.post(`/api/notifications/${id}/read`, {});
}

export async function markAllNotificationsRead() {
  return api.post('/api/notifications/read-all', {});
}
