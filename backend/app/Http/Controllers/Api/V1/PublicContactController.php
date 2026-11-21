<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;

/** How to reach the SASA team, for the public website. Each value may be null. */
class PublicContactController extends Controller
{
    public function __invoke()
    {
        $contact = (array) config('sasa.contact', []);

        return ApiResponse::data([
            'email' => self::value($contact['email'] ?? null),
            'phone' => self::value($contact['phone'] ?? null),
            'whatsapp' => self::value($contact['whatsapp'] ?? null),
        ]);
    }

    private static function value(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
