<?php

namespace App\Domain\Billing;

/**
 * Snippe webhook verification, exactly as documented: HMAC-SHA256 over
 * "{X-Webhook-Timestamp}.{raw body}" with the webhook signing key, hex
 * encoded, compared in constant time, and refused when older than the
 * tolerance (replay guard).
 */
final class SnippeWebhook
{
    public static function sign(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    public function verify(?string $timestamp, ?string $signature, string $rawBody): bool
    {
        $secret = (string) config('sasa.billing.snippe.webhook_secret');

        if ($secret === '' || ! $timestamp || ! $signature || ! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = (int) config('sasa.billing.snippe.webhook_tolerance_seconds', 300);

        if ($tolerance > 0 && abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(self::sign($timestamp, $rawBody, $secret), strtolower(trim($signature)));
    }
}
