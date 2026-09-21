<?php

namespace App\Rules;

use App\Support\Whole;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A quantity typed into a form: a whole number, at least $min. Replaces the
 * numeric/gt rules on every quantity field so the message is plain English
 * ("Enter a whole number, like 3") rather than "must be an integer".
 */
class WholeNumber implements ValidationRule
{
    public function __construct(private readonly int $min = 0)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Whole::is($value)) {
            $fail('Enter a whole number, like 3 — no decimals.');

            return;
        }

        if ((float) $value < $this->min) {
            $fail($this->min === 1 ? 'Enter a whole number of 1 or more.' : "Enter a whole number of {$this->min} or more.");
        }
    }
}
