import { api, setAuthToken } from './api';

export const auth = {
  async login(email, password) {
    const data = await api.post('/api/login', { email, password });
    if (data?.token) setAuthToken(data.token);
    return data;
  },
  async register(name, email, password, passwordConfirmation) {
    // Register intentionally does not auto-login — it returns a success card, then the user
    // verifies their email and continues to login. (No token is returned, so nothing to store.)
    return api.post('/api/register', {
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    });
  },
  async verifyEmail(email, token) {
    return api.post('/api/verify-email', { email, token });
  },
  async resendVerification(email) {
    return api.post('/api/resend-verification', { email });
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

