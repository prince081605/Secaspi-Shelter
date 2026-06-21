import { api } from './api';

export async function adminGetOverview() {
  return api.get('/api/admin/dashboard/overview');
}
