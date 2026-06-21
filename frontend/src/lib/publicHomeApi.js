import { api } from './api';

// API endpoints intended for public Home page content.
// These may be backed by real DB queries later; for now we keep mockable shapes.

export async function getHomeStats() {
  // Expected shape:
  // { items: [{ value: '200+', label: 'Animals currently helped' }, ...] }
  return api.get('/home/stats');
}

export async function getFeaturedAnimals() {
  // Expected shape:
  // { animals: [{ id, name, species, age, status }, ...] }
  // Public endpoints live under /api in Laravel
  return api.get('/api/home/featured-animals');
}

export async function getImpactStats() {
  // Expected shape:
  // { animals_rescued, animals_adopted, donations_raised, rescue_reports_handled,
  //   success_rate, top_donors: [{ name, total }, ...] }
  return api.get('/api/home/impact');
}


