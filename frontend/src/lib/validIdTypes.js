/*
 * The IDs a volunteer applicant may present, as government-issued or school-issued proof of
 * who they are. Volunteers work with animals and, at adoption events, with the public, so the
 * shelter records an ID rather than taking a name on trust.
 *
 * Kept in sync by hand with VolunteerApplicationController::ID_TYPES on the backend, which
 * validates against the same keys — same arrangement as donationCategories.js. A backend test
 * rejects an unknown type, so drift shows up as a failing test rather than a silent accept.
 */
export const ID_TYPES = [
  { value: 'school_id', label: 'School ID' },
  { value: 'national_id', label: 'National ID (PhilSys)' },
  { value: 'umid', label: 'UMID' },
  { value: 'drivers_license', label: "Driver's License" },
  { value: 'postal_id', label: 'Postal ID' },
  { value: 'philhealth', label: 'PhilHealth' },
  { value: 'passport', label: 'Passport' },
  { value: 'other', label: 'Other' },
];

// Falls back to the stored key, so a row written before a type was renamed still reads as
// something rather than as a blank.
export function labelForIdType(value) {
  return ID_TYPES.find((t) => t.value === value)?.label || value || '';
}
