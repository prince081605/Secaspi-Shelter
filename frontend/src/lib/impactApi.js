import { api } from './api';

// ---- Donor & volunteer impact (gamification) ----

export const getMyImpact = () => api.get('/api/impact/me');
export const getLeaderboard = () => api.get('/api/impact/leaderboard');
