import { api } from './api';

// ---- Messaging (member ⇄ staff/admin) ----

export const listConversations = () => api.get('/api/conversations');
export const listAdminConversations = () => api.get('/api/admin/conversations');
export const startConversation = (subject, body) => api.post('/api/conversations', { subject, body });
export const getConversation = (id) => api.get(`/api/conversations/${id}`);
export const replyConversation = (id, body) => api.post(`/api/conversations/${id}/messages`, { body });
