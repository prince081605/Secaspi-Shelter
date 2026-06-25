import { api, setAuthToken } from './api';

export const auth = {
  async login(email, password) {
    const data = await api.post('/api/login', { email, password });
    if (data?.token) setAuthToken(data.token);
    return data;
  },
  async register(name, email, password) {
    const data = await api.post('/api/register', { name, email, password });
    if (data?.token) setAuthToken(data.token);
    return data;
  },
  async suggestUsername(name) {
    const data = await api.post('/api/username/suggest', { name });
    return data?.username || '';
  },
  async me() {
    return api.get('/api/user');
  },
  async forgotPassword(email) {
    return api.post('/api/forgot-password', { email });
  },
  async resetPassword(email, token, password) {
    return api.post('/api/reset-password', { email, token, password });
  },
  async logout() {
    try {
      await api.post('/api/logout');
    } finally {
      setAuthToken(null);
    }
  },
};

