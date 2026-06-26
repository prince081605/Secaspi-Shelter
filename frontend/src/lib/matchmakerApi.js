import { api } from './api';

// Smart Adoption Matchmaker — POST lifestyle answers, get ranked animal matches.
export async function findMatches(answers) {
  return api.post('/api/matchmaker', answers);
}
