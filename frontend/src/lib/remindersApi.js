import { api } from './api';

// ---- Admin: health reminders (vaccination boosters, follow-ups) ----

export async function adminListReminders(days = 30) {
  return api.get(`/api/admin/reminders?days=${days}`);
}

export async function adminUpdateReminder(id, status) {
  return api.put(`/api/admin/reminders/${id}`, { status });
}
