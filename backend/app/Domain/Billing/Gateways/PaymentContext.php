<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\PhoneNumber;
use App\Models\Invoice;
use App\Models\SubscriptionRequest;
use App\Models\User;

/**
 * Who is paying and where they return to: everything a gateway needs beyond
 * the payment itself. A payment is either for a website subscription request
 * or for an organisation's invoice; this keeps the gateways ignorant of which.
 *
 * URL templates use {web_url} (sasa.web_url) and {payment} (the payment's
 * public id).
 */
final class PaymentContext
{
    /** @param array<string,string> $metadata sent to the provider alongside payment_id */
    public function __construct(
        public readonly string $firstname,
        public readonly string $lastname,
        public readonly string $email,
        public readonly string $phone,
        public readonly ?string $country,
        public readonly array $metadata,
        public readonly string $cardReturnUrl,
        public readonly string $cardCancelUrl,
        public readonly string $sandboxCardCheckoutUrl,
    ) {}

    public static function forSubscription(SubscriptionRequest $request): self
    {
        [$first, $last] = self::splitName((string) $request->contact_name, (string) $request->organisation_name);

        return new self(
            firstname: $first,
            lastname: $last,
            email: (string) $request->email,
            phone: PhoneNumber::normaliseTanzanian($request->phone) ?? (string) preg_replace('/\D+/', '', (string) $request->phone),
            country: $request->country,
            metadata: ['subscription_reference' => (string) $request->reference],
            cardReturnUrl: (string) config('sasa.billing.card_return_url'),
            cardCancelUrl: (string) config('sasa.billing.card_cancel_url'),
            sandboxCardCheckoutUrl: (string) config('sasa.billing.sandbox.card_checkout_url'),
        );
    }

    /**
     * An invoice paid by a person in the organisation's panel ($payer), or
     * by auto-pay ($payer null: the organisation's contact details are used).
     */
    public static function forInvoice(Invoice $invoice, ?User $payer, ?string $phone): self
    {
        $invoice->loadMissing('organisation');
        $organisation = $invoice->organisation;

        [$first, $last] = self::splitName((string) ($payer?->name ?? ''), (string) $organisation?->name);

        $email = (string) ($payer?->email ?: $organisation?->contact_email ?: config('sasa.contact.email') ?: '');

        $phone = PhoneNumber::normaliseTanzanian($phone)
            ?? PhoneNumber::normaliseTanzanian($organisation?->contact_phone)
            ?? PhoneNumber::normaliseTanzanian($payer?->phone)
            ?? '';

        return new self(
            firstname: $first,
            lastname: $last,
            email: $email,
            phone: $phone,
            country: $organisation?->country,
            metadata: ['invoice_number' => (string) $invoice->number],
            cardReturnUrl: (string) config('sasa.billing.invoice_card_return_url'),
            cardCancelUrl: (string) config('sasa.billing.invoice_card_cancel_url'),
            sandboxCardCheckoutUrl: (string) config('sasa.billing.sandbox.invoice_card_checkout_url'),
        );
    }

    /** Fill a URL template for a payment. */
    public static function url(string $template, string $paymentPublicId): string
    {
        return strtr($template, [
            '{web_url}' => rtrim((string) config('sasa.web_url'), '/'),
            '{payment}' => $paymentPublicId,
        ]);
    }

    /** @return array{0:string,1:string} */
    private static function splitName(string $name, string $fallback): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = array_shift($parts) ?: ($fallback !== '' ? $fallback : 'SASA customer');
        $last = $parts !== [] ? implode(' ', $parts) : $first;

        return [$first, $last];
    }
}
