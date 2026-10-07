<?php

namespace App\Rules;

use App\Models\VolunteerAttendance;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * For dates that record something that already happened (a vet visit, a shot given, money
 * spent). "Today" is the shelter's day in Manila, not the server's UTC day — otherwise a
 * record entered before 8 AM would be refused as "tomorrow".
 */
class NotInFuture implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $date = CarbonImmutable::parse($value, VolunteerAttendance::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            return; // The 'date' rule reports values that aren't dates.
        }

        if ($date->greaterThan(CarbonImmutable::now(VolunteerAttendance::TIMEZONE)->startOfDay())) {
            $fail('The :attribute cannot be in the future.');
        }
    }
}
