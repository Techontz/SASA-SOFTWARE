<?php

namespace App\Domain\Billing;

/**
 * Tanzanian mobile numbers, as people actually type them: 0712 345 678,
 * 0612345678, +255 712 345 678, 255712345678 or 712345678. All of them become
 * 2557XXXXXXXX / 2556XXXXXXXX, which is what the mobile money push needs. The
 * network is never asked for: the gateway works it out from the number.
 */
final class PhoneNumber
{
    /** The normalised number, or null when it is not a Tanzanian mobile number. */
    public static function normaliseTanzanian(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        $national = match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '255') => substr($digits, 3),
            strlen($digits) === 10 && str_starts_with($digits, '0') => substr($digits, 1),
            strlen($digits) === 9 => $digits,
            default => null,
        };

        if ($national === null || ! preg_match('/^[67]\d{8}$/', $national)) {
            return null;
        }

        return '255'.$national;
    }

    public static function isTanzanian(?string $input): bool
    {
        return self::normaliseTanzanian($input) !== null;
    }
}
