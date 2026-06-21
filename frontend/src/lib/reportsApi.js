import { api, downloadFile } from './api';

export const REPORT_TYPES = [
  { key: 'adoption', label: 'Adoption Applications' },
  { key: 'animals', label: 'Animals' },
  { key: 'medical', label: 'Medical & Vaccinations' },
  { key: 'donations', label: 'Donations' },
  { key: 'volunteers', label: 'Volunteers' },
  { key: 'rescue', label: 'Rescue Reports' },
];

function qs(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const s = search.toString();
  return s ? `?${s}` : '';
}

export async function getReport(type, params = {}) {
  return api.get(`/api/admin/reports/${type}${qs(params)}`);
}

export async function exportReport(type, format, params = {}) {
  return downloadFile(`/api/admin/reports/export/${format}${qs({ type, ...params })}`);
}
