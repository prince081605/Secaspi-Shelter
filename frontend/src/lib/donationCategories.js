// Single source of truth for donation expense categories on the frontend. Keys and labels mirror
// backend App\Support\DonationCategories::DEFAULTS. A donation with no category (null/'') means the
// donor chose "where it's needed most".

// `defaultGoal` mirrors the backend defaults and is used only as the Settings-form placeholder;
// the effective goal comes from the `cat_goal_*` settings on the server.
export const DONATION_CATEGORIES = [
  { key: 'personnel', label: 'Personnel Expenses', defaultGoal: 17760 },
  { key: 'facility', label: 'Facility / Shelter Expenses', defaultGoal: 21700 },
  { key: 'transportation', label: 'Transportation Expenses', defaultGoal: 720 },
  { key: 'animal_care', label: 'Animal Care / Food Expenses', defaultGoal: 35500 },
  { key: 'cleaning', label: 'Cleaning & Sanitation Supplies', defaultGoal: 4540 },
];

// Settings key that holds a category's admin-overridden monthly goal.
export const goalSettingKey = (key) => `cat_goal_${key}`;

export const NEEDED_MOST_LABEL = "Where it's needed most";

// Form-only sentinel for the explicit "needed most" choice. It is NOT sent to the API — the donor
// picking it results in a null category (see Donate.jsx), which the backend pools toward the
// neediest category. Kept distinct from '' so a disabled placeholder option can use '' and native
// `required` validation works.
export const NEEDED_MOST_VALUE = 'needed_most';

const LABELS = Object.fromEntries(DONATION_CATEGORIES.map((c) => [c.key, c.label]));

// Human label for a stored category value (null/'' → "Where it's needed most").
export function labelFor(key) {
  if (!key) return NEEDED_MOST_LABEL;
  return LABELS[key] || key;
}
