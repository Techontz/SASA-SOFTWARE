<?php

namespace App\Domain\Billing\Gateways;

/**
 * What a gateway reports about a payment, already translated into SASA's
 * statuses: pending | succeeded | failed | cancelled.
 */
final class GatewayResult
{
    /** @param array<string,mixed> $raw the provider's own payload, kept for reconciliation */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $checkoutUrl = null,
        public readonly ?string $failureReason = null,
        public readonly array $raw = [],
    ) {}

    public static function pending(?string $providerReference = null, ?string $checkoutUrl = null, array $raw = []): self
    {
        return new self('pending', $providerReference, $checkoutUrl, null, $raw);
    }

    public static function failed(string $reason, array $raw = []): self
    {
        return new self('failed', null, null, $reason, $raw);
    }
}
