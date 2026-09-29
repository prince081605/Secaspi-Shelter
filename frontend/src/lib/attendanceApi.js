import { api } from './api';

// ---- Self-service: the signed-in volunteer's / staff member's own clock ----

export async function getMyAttendance() {
  return api.get('/api/volunteer/attendance');
}

export async function clockIn(payload = {}) {
  return api.post('/api/volunteer/attendance/clock-in', payload);
}

export async function clockOut() {
  return api.post('/api/volunteer/attendance/clock-out');
}

// ---- Admin: the attendance log (staff can read; only admins write) ----

export async function adminListAttendance(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return api.get(`/api/admin/attendance${qs ? `?${qs}` : ''}`);
}

export async function adminCreateAttendance(volunteerId, payload) {
  return api.post(`/api/admin/volunteers/${volunteerId}/attendance`, payload);
}

export async function adminUpdateAttendance(id, payload) {
  return api.put(`/api/admin/attendance/${id}`, payload);
}

export async function adminDeleteAttendance(id) {
  return api.delete(`/api/admin/attendance/${id}`);
}

// ---- Display helpers ----

export function formatDateTime(value) {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export function formatTime(value) {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

/** 90 → "1h 30m"; null → "—". */
export function formatDuration(minutes) {
  if (minutes === null || minutes === undefined) return '—';
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (!h) return `${m}m`;
  return m ? `${h}h ${m}m` : `${h}h`;
}

/** A Date/ISO string → the value a <input type="datetime-local"> expects, in local time. */
export function toLocalInput(value) {
  if (!value) return '';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** A datetime-local value (local time) → an ISO string with offset, so the server stores the right instant. */
export function fromLocalInput(value) {
  if (!value) return null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}
