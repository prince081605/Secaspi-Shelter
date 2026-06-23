import { api } from './api';

// API endpoints intended for public Home page content.

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

export async function getTransparency() {
  // Expected shape:
  // { monthly_goal, this_month_raised, progress_pct, total_raised, donation_count,
  //   donor_count, by_method: { method: total }, monthly_trend: [{ label, total }],
  //   recent_donations: [{ name, amount, date }], fund_usage_image }
  return api.get('/api/home/transparency');
}


