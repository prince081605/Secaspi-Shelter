<?php

namespace App\Rules;

use App\Support\AnimalAge;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An animal's age as entered: a whole number with its unit (`age_unit`: weeks, months, years),
 * no "0 years" (a puppy's age goes in months or weeks), and no more than 40 years.
 */
class ValidAge implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($problem = AnimalAge::problem($value, $this->data['age_unit'] ?? null)) {
            $fail($problem);
        }
    }
}
