import { api } from './api';

// ---- Admin: insights & analytics dashboard ----

export async function getAnalyticsOverview() {
  return api.get('/api/admin/analytics/overview');
}
