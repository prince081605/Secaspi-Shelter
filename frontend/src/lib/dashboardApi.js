import { api } from './api';

export async function adminGetOverview() {
  return api.get('/api/admin/dashboard/overview');
}

// One request for all the sidebar "needs attention" badge counts, replacing 7 separate polls.
export async function adminGetPendingCounts() {
  return api.get('/api/admin/dashboard/pending-counts');
}
