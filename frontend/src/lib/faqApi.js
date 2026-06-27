import { api } from './api';

// ---- Admin: AI assistant FAQ knowledge base (training) ----

export const adminListFaqs = () => api.get('/api/admin/faqs');
export const adminCreateFaq = (data) => api.post('/api/admin/faqs', data);
export const adminUpdateFaq = (id, data) => api.put(`/api/admin/faqs/${id}`, data);
export const adminDeleteFaq = (id) => api.delete(`/api/admin/faqs/${id}`);
