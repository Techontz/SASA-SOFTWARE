<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\PhoneNumber;
use App\Models\Payment;
use App\Models\SubscriptionRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Snippe (https://docs.snippe.sh), API version 2026-01-25.
 *
 * POST /v1/payments with payment_type "mobile" sends a USSD push to the phone;
 * the network (M-Pesa, Airtel Money, Mixx by Yas, Halotel) is not chosen by
 * the payer. payment_type "card" returns a hosted `payment_url`. Both settle
 * in TZS only. GET /v1/payments/{reference} reports the status; webhooks
 * (payment.completed / payment.failed / ...) are verified in SnippeWebhook.
 */
final class SnippeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'snippe';
    }

    public function startMobileMoney(Payment $payment, SubscriptionRequest $request): GatewayResult
    {
        return $this->create($payment, [
            'payment_type' => 'mobile',
            'details' => [
                'amount' => $this->minorUnits($payment),
                'currency' => $payment->currency,
            ],
            'phone_number' => $payment->phone,
            'customer' => $this->customer($request),
        ] + $this->common($payment, $request));
    }

    public function startCard(Payment $payment, SubscriptionRequest $request): GatewayResult
    {
        $defaults = (array) config('sasa.billing.snippe.card_customer_defaults', []);
        $country = strtoupper(trim((string) $request->country));

        return $this->create($payment, [
            'payment_type' => 'card',
            'details' => [
                'amount' => $this->minorUnits($payment),
                'currency' => $payment->currency,
                'redirect_url' => $this->url('card_return_url', $payment),
                'cancel_url' => $this->url('card_cancel_url', $payment),
            ],
            'phone_number' => PhoneNumber::normaliseTanzanian($request->phone) ?? preg_replace('/\D+/', '', (string) $request->phone),
            'customer' => $this->customer($request) + [
                'address' => (string) ($defaults['address'] ?? ''),
                'city' => (string) ($defaults['city'] ?? ''),
                'state' => (string) ($defaults['state'] ?? ''),
                'postcode' => (string) ($defaults['postcode'] ?? ''),
                'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : (string) ($defaults['country'] ?? 'TZ'),
            ],
        ] + $this->common($payment, $request));
    }

    public function fetchStatus(Payment $payment): ?GatewayResult
    {
        if (! $payment->provider_reference) {
            return null;
        }

        try {
            $response = $this->http()->get('/v1/payments/'.rawurlencode($payment->provider_reference));
        } catch (ConnectionException $e) {
            Log::warning('billing.snippe.status_unreachable', ['payment' => $payment->public_id, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('billing.snippe.status_failed', ['payment' => $payment->public_id, 'status' => $response->status()]);

            return null;
        }

        $data = (array) $response->json('data', []);

        return new GatewayResult(
            self::mapStatus((string) ($data['status'] ?? 'pending')),
            $data['reference'] ?? $payment->provider_reference,
            $data['payment_url'] ?? null,
            isset($data['failure_reason']) ? (string) $data['failure_reason'] : self::reasonFor((string) ($data['status'] ?? '')),
            $data,
        );
    }

    /** Snippe's statuses, in SASA's words. */
    public static function mapStatus(string $status): string
    {
        return match ($status) {
            'completed' => 'succeeded',
            'failed', 'expired' => 'failed',
            'voided' => 'cancelled',
            default => 'pending',
        };
    }

    public static function reasonFor(string $status): ?string
    {
        return match ($status) {
            'expired' => 'The payment was not completed in time.',
            'voided' => 'The payment was cancelled.',
            'failed' => 'The payment was declined.',
            default => null,
        };
    }

    /** @param array<string,mixed> $body */
    private function create(Payment $payment, array $body): GatewayResult
    {
        try {
            // The payment's public id (a 26-character ULID) is the idempotency
            // key: Snippe allows at most 30 characters.
            $response = $this->http()
                ->withHeaders(['Idempotency-Key' => $payment->public_id])
                ->post('/v1/payments', $body);
        } catch (ConnectionException $e) {
            Log::error('billing.snippe.unreachable', ['payment' => $payment->public_id, 'error' => $e->getMessage()]);

            return GatewayResult::failed('We could not reach the payment provider. Nothing was charged. Please try again.', ['error' => 'connection']);
        }

        return $this->fromCreateResponse($response);
    }

    private function fromCreateResponse(Response $response): GatewayResult
    {
        $json = (array) ($response->json() ?? []);

        if (! $response->successful()) {
            Log::warning('billing.snippe.refused', [
                'status' => $response->status(),
                'error_code' => $json['error_code'] ?? null,
                'message' => $json['message'] ?? null,
            ]);

            $message = isset($json['message']) && is_string($json['message'])
                ? 'The payment provider refused the payment: '.$json['message'].'.'
                : 'The payment provider refused the payment. Nothing was charged.';

            return GatewayResult::failed($message, ['http_status' => $response->status()] + $json);
        }

        $data = (array) ($json['data'] ?? []);

        return new GatewayResult(
            self::mapStatus((string) ($data['status'] ?? 'pending')),
            isset($data['reference']) ? (string) $data['reference'] : null,
            isset($data['payment_url']) ? (string) $data['payment_url'] : null,
            self::reasonFor((string) ($data['status'] ?? '')),
            $data,
        );
    }

    /** @return array<string,mixed> */
    private function common(Payment $payment, SubscriptionRequest $request): array
    {
        return [
            'webhook_url' => (string) config('sasa.billing.snippe.webhook_url'),
            'metadata' => [
                'payment_id' => $payment->public_id,
                'subscription_reference' => $request->reference,
            ],
        ];
    }

    /** @return array{firstname:string,lastname:string,email:string} */
    private function customer(SubscriptionRequest $request): array
    {
        $parts = preg_split('/\s+/', trim((string) $request->contact_name)) ?: [];
        $first = array_shift($parts) ?: (string) $request->organisation_name;
        $last = $parts !== [] ? implode(' ', $parts) : $first;

        return ['firstname' => $first, 'lastname' => $last, 'email' => (string) $request->email];
    }

    private function minorUnits(Payment $payment): int
    {
        return (int) round((float) $payment->amount * (int) config('sasa.billing.snippe.amount_multiplier', 1));
    }

    private function url(string $key, Payment $payment): string
    {
        return strtr((string) config('sasa.billing.'.$key), [
            '{web_url}' => rtrim((string) config('sasa.web_url'), '/'),
            '{payment}' => $payment->public_id,
        ]);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('sasa.billing.snippe.base_url'), '/'))
            ->withToken((string) config('sasa.billing.snippe.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('sasa.billing.snippe.timeout_seconds', 20));
    }
}
