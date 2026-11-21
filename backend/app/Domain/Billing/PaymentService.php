<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Gateways\GatewayResult;
use App\Domain\Billing\Gateways\PaymentContext;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Gateways\SnippeGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Paying for a subscription request (the public website journey) or for an
 * organisation's invoice (its agreed monthly plan): start a payment, follow it
 * to an outcome, and mark the request or invoice paid the moment money is
 * confirmed.
 *
 * Mobile money is always a USSD push: the payer approves it on their phone
 * with their PIN. Nothing here can take money from anyone without that.
 *
 * Every status change on a payment, request or invoice is written to the audit
 * trail by the Auditable trait (payment.status_changed, ...status_changed).
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
        private readonly InvoiceSettlement $settlement,
    ) {}

    /**
     * What each payment method would charge for this request. A method that
     * cannot settle in the request's currency charges the plan's price in one
     * it can (Snippe settles in TZS only).
     *
     * @return array{amount:string,currency:string}|null null when the method cannot be used
     */
    public function chargeFor(SubscriptionRequest $request, string $method): ?array
    {
        if ($request->amount === null) {
            return null;
        }

        $allowed = (array) config("sasa.billing.charge_currencies.{$method}", []);

        if ($allowed === [] || in_array($request->currency, $allowed, true)) {
            return ['amount' => (string) $request->amount, 'currency' => $request->currency];
        }

        foreach ($allowed as $currency) {
            $price = $request->plan?->priceIn($currency);

            if ($price !== null) {
                return ['amount' => $price, 'currency' => $currency];
            }
        }

        return null;
    }

    /** @return array<string,array{amount:float,currency:string}|null> */
    public function chargeOptions(SubscriptionRequest $request): array
    {
        $options = [];

        foreach (Payment::METHODS as $method) {
            $charge = $this->chargeFor($request, $method);
            $options[$method] = $charge ? ['amount' => (float) $charge['amount'], 'currency' => $charge['currency']] : null;
        }

        return $options;
    }

    public function start(SubscriptionRequest $request, string $method, ?string $phone): Payment
    {
        $request->loadMissing('plan');

        match ($request->status) {
            'pending_payment' => null,
            'paid', 'active' => throw new DomainRuleException("{$request->reference} is already paid.", 'already_paid'),
            'quote_requested' => throw new DomainRuleException('This plan is priced on request. We will contact you with a quote; there is nothing to pay online.', 'quote_only'),
            default => throw new DomainRuleException("{$request->reference} is closed and can no longer be paid.", 'request_closed'),
        };

        $charge = $this->chargeFor($request, $method)
            ?? throw new DomainRuleException('This plan cannot be paid that way. Choose another payment method.', 'method_unavailable');

        return $this->begin(
            ['subscription_request_id' => $request->id],
            fn () => $request->payments(),
            PaymentContext::forSubscription($request),
            $method,
            $phone,
            $charge,
        );
    }

    /**
     * Pay an organisation's invoice. `$payer` is the person paying from the
     * organisation's panel, or null for auto-pay (the billing cycle sending
     * the prompt by itself). Either way, mobile money is a prompt on the
     * phone that the payer must approve with their PIN.
     */
    public function startForInvoice(Invoice $invoice, string $method, ?string $phone, ?User $payer = null): Payment
    {
        match ($invoice->status) {
            'open', 'overdue' => null,
            'paid' => throw new DomainRuleException("{$invoice->number} is already paid.", 'already_paid'),
            default => throw new DomainRuleException("{$invoice->number} was cancelled and can no longer be paid.", 'invoice_void'),
        };

        $allowed = (array) config("sasa.billing.charge_currencies.{$method}", []);

        if ($allowed !== [] && ! in_array($invoice->currency, $allowed, true)) {
            throw new DomainRuleException('This invoice cannot be paid that way. Choose another payment method.', 'method_unavailable');
        }

        return $this->begin(
            ['invoice_id' => $invoice->id],
            fn () => $invoice->payments(),
            PaymentContext::forInvoice($invoice, $payer, $phone),
            $method,
            $phone,
            ['amount' => (string) $invoice->amount, 'currency' => $invoice->currency],
        );
    }

    /**
     * @param  array<string,int>  $owner  subscription_request_id or invoice_id
     * @param  \Closure():HasMany  $attempts  the owner's payments
     * @param  array{amount:string,currency:string}  $charge
     */
    private function begin(array $owner, \Closure $attempts, PaymentContext $context, string $method, ?string $phone, array $charge): Payment
    {
        $normalisedPhone = $method === 'mobile_money' ? PhoneNumber::normaliseTanzanian($phone) : null;

        if ($method === 'mobile_money' && $normalisedPhone === null) {
            throw new DomainRuleException('Enter a Tanzanian mobile number.', 'invalid_phone', 422, [
                'errors' => ['phone' => ['Enter a Tanzanian mobile number, for example 0712 345 678.']],
            ]);
        }

        $minimum = (int) config('sasa.billing.snippe.minimum_amount', 0);

        if ($this->gateway->name() === 'snippe' && $charge['currency'] === 'TZS' && (float) $charge['amount'] < $minimum) {
            throw new DomainRuleException("The payment provider's minimum is TZS {$minimum}.", 'amount_below_minimum');
        }

        $payment = DB::transaction(function () use ($owner, $attempts, $method, $normalisedPhone, $charge) {
            // Only one attempt is live at a time. An older pending attempt is
            // closed here; if its money still arrives, it is honoured.
            $attempts()->where('status', 'pending')->get()->each(function (Payment $old) {
                $old->forceFill(['status' => 'cancelled', 'failure_reason' => 'Replaced by a newer payment attempt.'])->save();
            });

            $payment = new Payment;
            $payment->fill($owner + [
                'provider' => $this->gateway->name(),
                'method' => $method,
                'phone' => $normalisedPhone,
                'amount' => $charge['amount'],
                'currency' => $charge['currency'],
            ]);
            $payment->public_id = (string) Str::ulid();
            $payment->status = 'pending';
            $payment->save();

            return $payment;
        });

        $result = $method === 'mobile_money'
            ? $this->gateway->startMobileMoney($payment, $context)
            : $this->gateway->startCard($payment, $context);

        $this->apply($payment, $result, 'create');

        return $payment->fresh();
    }

    /**
     * The polling read. While a payment is pending, ask the gateway (at most
     * every few seconds for a real provider) whether anything has changed.
     */
    public function refresh(Payment $payment): Payment
    {
        if (! $payment->isPending() || $payment->provider !== $this->gateway->name()) {
            return $payment;
        }

        if ($payment->provider !== 'sandbox') {
            $seconds = max(1, (int) config('sasa.billing.snippe.status_poll_seconds', 5));

            if (! Cache::add('billing:status-poll:'.$payment->public_id, 1, $seconds)) {
                return $payment;
            }
        }

        $result = $this->gateway->fetchStatus($payment);

        if ($result !== null) {
            $this->apply($payment, $result, 'status');
        }

        return $payment->fresh();
    }

    /** The sandbox card page's "Pay" and "Decline" buttons. */
    public function completeSandbox(Payment $payment, string $outcome): Payment
    {
        if (! $payment->isPending()) {
            throw new DomainRuleException('This payment is already finished.', 'payment_finished');
        }

        $this->apply($payment, new GatewayResult(
            $outcome === 'succeeded' ? 'succeeded' : 'failed',
            null,
            null,
            $outcome === 'succeeded' ? null : 'The card was declined (sandbox).',
            ['simulated' => 'card_'.$outcome],
        ), 'sandbox_complete');

        return $payment->fresh();
    }

    /**
     * A verified Snippe webhook. Idempotent: the same event delivered twice,
     * or an event for a payment already settled, changes nothing.
     *
     * @param  array<string,mixed>  $event
     * @return string what happened, for the response and the log
     */
    public function handleSnippeEvent(array $event): string
    {
        $data = (array) ($event['data'] ?? []);
        $reference = isset($data['reference']) ? (string) $data['reference'] : null;
        $metadataId = isset($data['metadata']['payment_id']) ? (string) $data['metadata']['payment_id'] : null;

        $payment = ($reference ? Payment::query()->where('provider', 'snippe')->where('provider_reference', $reference)->first() : null)
            ?? ($metadataId ? Payment::query()->where('provider', 'snippe')->where('public_id', $metadataId)->first() : null);

        if (! $payment) {
            Log::info('billing.snippe.webhook_unknown_payment', ['reference' => $reference, 'type' => $event['type'] ?? null]);

            return 'ignored_unknown_payment';
        }

        return DB::transaction(function () use ($payment, $event, $data) {
            /** @var Payment $payment */
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $eventId = isset($event['id']) ? (string) $event['id'] : null;
            $seen = collect($payment->raw['webhooks'] ?? [])->pluck('id')->filter()->all();

            if ($eventId !== null && in_array($eventId, $seen, true)) {
                return 'duplicate';
            }

            $status = SnippeGateway::mapStatus((string) ($data['status'] ?? ''));
            $type = (string) ($event['type'] ?? '');

            if ($status === 'pending') {
                $status = match ($type) {
                    'payment.completed' => 'succeeded',
                    'payment.failed', 'payment.expired' => 'failed',
                    'payment.voided' => 'cancelled',
                    default => 'pending',
                };
            }

            $payment->appendRaw('webhooks', $event);

            if ($payment->status === 'succeeded' || $status === 'pending') {
                $payment->save();

                return 'unchanged';
            }

            // Money must match what was asked for before it counts.
            if ($status === 'succeeded' && ! $this->amountMatches($payment, $data)) {
                $payment->save();
                Log::error('billing.snippe.amount_mismatch', ['payment' => $payment->public_id, 'amount' => $data['amount'] ?? null]);
                $this->audit->record(
                    action: 'payment.amount_mismatch',
                    entity: $payment,
                    summary: 'A provider reported success for an amount that does not match the payment.',
                    context: ['reported' => $data['amount'] ?? null],
                );

                return 'amount_mismatch';
            }

            // A late failure for an attempt already closed locally changes nothing.
            if ($status !== 'succeeded' && $payment->status !== 'pending') {
                $payment->save();

                return 'unchanged';
            }

            $reason = isset($data['failure_reason']) ? (string) $data['failure_reason'] : SnippeGateway::reasonFor((string) ($data['status'] ?? ''));
            $this->transition($payment, $status, $reason);

            return 'updated';
        });
    }

    private function apply(Payment $payment, GatewayResult $result, string $source): void
    {
        DB::transaction(function () use ($payment, $result, $source) {
            $payment->refresh();

            if ($result->raw !== []) {
                $payment->appendRaw($source, $result->raw);
            }

            if ($result->providerReference && ! $payment->provider_reference) {
                $payment->provider_reference = $result->providerReference;
            }

            if ($result->checkoutUrl) {
                $payment->checkout_url = $result->checkoutUrl;
            }

            if ($result->status !== 'pending' && $payment->status !== 'succeeded') {
                $this->transition($payment, $result->status, $result->failureReason);

                return;
            }

            $payment->save();
        });
    }

    /** Move a payment to an outcome; success marks the request or invoice paid. */
    private function transition(Payment $payment, string $status, ?string $reason): void
    {
        $payment->status = $status;
        $payment->failure_reason = $status === 'succeeded' ? null : ($reason ? Str::limit($reason, 490) : null);

        if ($status === 'succeeded') {
            $payment->paid_at = now();
        }

        $payment->save();

        if ($status === 'succeeded' && $payment->invoice_id !== null) {
            $this->settlement->paidByPayment($payment);

            return;
        }

        if ($status === 'succeeded') {
            $request = SubscriptionRequest::query()->lockForUpdate()->find($payment->subscription_request_id);

            if ($request && $request->status === 'pending_payment') {
                $request->forceFill(['status' => 'paid'])->save();
            } elseif ($request && $request->status !== 'paid' && $request->status !== 'active') {
                // Money arrived for a request already closed by hand: say so.
                $this->audit->record(
                    action: 'subscription_request.paid_after_close',
                    entity: $request,
                    summary: 'A payment succeeded after the request was '.$request->status.'.',
                    context: ['payment' => $payment->public_id],
                );
            }
        }
    }

    /** @param array<string,mixed> $data */
    private function amountMatches(Payment $payment, array $data): bool
    {
        $amount = $data['amount'] ?? null;

        if (! is_array($amount) || ! isset($amount['value'])) {
            return true; // nothing reported to compare against
        }

        $expected = (int) round((float) $payment->amount * (int) config('sasa.billing.snippe.amount_multiplier', 1));

        return (int) $amount['value'] === $expected
            && (! isset($amount['currency']) || strtoupper((string) $amount['currency']) === $payment->currency);
    }
}
