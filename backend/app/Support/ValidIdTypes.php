<?php

namespace App\Support;

/**
 * The government- or school-issued IDs a person may present to prove who they are — asked of
 * volunteer applicants (they handle animals and meet the public) and adoption applicants (an
 * animal is being placed in their care).
 *
 * Kept in sync by hand with frontend/src/lib/validIdTypes.js, the same arrangement donation
 * categories use; the "unknown id type" tests are what catch the two lists drifting apart.
 */
class ValidIdTypes
{
    public const ALL = [
        'school_id',
        'national_id',
        'umid',
        'drivers_license',
        'postal_id',
        'philhealth',
        'passport',
        'other',
    ];
}
