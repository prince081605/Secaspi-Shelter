<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Philippine phone number, mobile or landline, the way people actually type them:
 *   0917 123 4567 · 0917-123-4567 · +63 917 123 4567 · (02) 8123 4567 · 049 123 4567
 *
 * Spaces, dashes, dots and brackets are ignored; what's left must be 0 or (+)63 followed by
 * 9–10 digits. That rejects letters, stray characters and numbers too short to dial, without
 * turning away a landline (which rescue reporters and the shelter itself may give).
 */
class PhilippinePhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/[\s\-().]/', '', (string) $value);

        if (! preg_match('/^(?:\+?63|0)\d{9,10}$/', $digits)) {
            $fail('The :attribute must be a valid Philippine phone number, e.g. 0917 123 4567.');
        }
    }
}
