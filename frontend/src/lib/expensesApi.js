import { api } from './api';

// The shelter's expense ledger. Reads are staff-accessible; writes are admin-only (the same line
// the backend draws at donation verification), so a staff session will see 403s from create/
// update/delete — the panel hides those controls rather than letting them fail.

function queryString(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== 'all') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return qs ? `?${qs}` : '';
}

export async function adminListExpenses(params = {}) {
  return api.get(`/api/admin/expenses${queryString(params)}`);
}

export async function adminGetExpenseStats(params = {}) {
  return api.get(`/api/admin/expenses/stats${queryString(params)}`);
}

// Create and update both go over multipart so a receipt image can ride along. Update is POST
// rather than PUT because PHP only populates $_FILES for POST — a multipart PUT arrives with an
// empty file bag.
export async function adminCreateExpense(formData) {
  return api.post('/api/admin/expenses', formData);
}

export async function adminUpdateExpense(id, formData) {
  return api.post(`/api/admin/expenses/${id}`, formData);
}

export async function adminDeleteExpense(id) {
  return api.delete(`/api/admin/expenses/${id}`);
}
