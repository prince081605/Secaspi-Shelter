// Roles and abilities on the frontend. Who holds which ability is decided on the server
// (backend App\Support\Permissions) and arrives with the signed-in user as `user.permissions`,
// so this file never repeats that map — it only reads it. Hiding a button here is a courtesy;
// the server enforces every check.

export const ROLE_LABELS = {
  user: 'User',
  volunteer: 'Volunteer',
  staff: 'Staff',
  desk_admin: 'Desk Admin',
  data_admin: 'Data Admin',
  system_admin: 'System Admin',
};

// In the order the Users page offers them.
export const ROLES = ['user', 'volunteer', 'staff', 'desk_admin', 'data_admin', 'system_admin'];

export const ADMIN_ROLES = ['desk_admin', 'data_admin', 'system_admin'];

// What each admin type is for, shown when a System Admin hands one out.
export const ADMIN_ROLE_SUMMARIES = {
  desk_admin: 'Runs the front desk: requests, records, donations, expenses and personnel. Uses the dropdown lists but cannot change them.',
  data_admin: 'Owns the dropdown lists and shelter areas, imports animals from Excel, and runs every report. No desk work and no accounts.',
  system_admin: 'Manages accounts and roles, site settings and security (blocked reporters). No desk work and no dropdown lists.',
};

export function roleLabel(role) {
  return ROLE_LABELS[role] || (role ? String(role) : '—');
}

export function isAdminRole(role) {
  return ADMIN_ROLES.includes(role);
}

// `user` is whatever /api/user returned (the user object, or the { user } wrapper).
export function can(user, ability) {
  const perms = user?.permissions || user?.user?.permissions || [];
  return perms.includes(ability);
}
