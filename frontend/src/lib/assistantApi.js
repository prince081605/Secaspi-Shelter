import { api } from './api';

// AI Shelter Assistant. `history` is the prior turns ([{role, content}]) for context.
export async function sendAssistantMessage(message, history = []) {
  return api.post('/api/assistant/chat', { message, history });
}
