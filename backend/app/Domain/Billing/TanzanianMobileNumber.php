<?php

namespace App\Domain\Billing;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validation for a number that can receive a mobile money push. */
final class TanzanianMobileNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneNumber::isTanzanian($value)) {
            $fail('Enter a Tanzanian mobile number, for example 0712 345 678 or +255 712 345 678.');
        }
    }
}
