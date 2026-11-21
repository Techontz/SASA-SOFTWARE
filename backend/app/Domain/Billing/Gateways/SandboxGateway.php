<?php

namespace App\Domain\Billing\Gateways;

use App\Models\Payment;

/**
 * Payments without a provider, for development and demos. Mobile money
 * succeeds by itself a few seconds after it starts (when the status is next
 * read); card sends the person to a SASA page that plays the checkout.
 */
final class SandboxGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function startMobileMoney(Payment $payment, PaymentContext $context): GatewayResult
    {
        return GatewayResult::pending('sandbox_'.$payment->public_id, null, ['simulated' => 'ussd_push']);
    }

    public function startCard(Payment $payment, PaymentContext $context): GatewayResult
    {
        $url = PaymentContext::url($context->sandboxCardCheckoutUrl, $payment->public_id);

        return GatewayResult::pending('sandbox_'.$payment->public_id, $url, ['simulated' => 'card_checkout']);
    }

    public function fetchStatus(Payment $payment): ?GatewayResult
    {
        if ($payment->method !== 'mobile_money' || ! $payment->isPending()) {
            return null;
        }

        $after = (int) config('sasa.billing.sandbox.auto_succeed_after_seconds', 6);

        if ($payment->created_at === null || $payment->created_at->gt(now()->subSeconds($after))) {
            return null;
        }

        $suffix = (string) config('sasa.billing.sandbox.failing_phone_suffix', '');

        if ($suffix !== '' && str_ends_with((string) $payment->phone, $suffix)) {
            return new GatewayResult('failed', null, null, 'The payment was declined on the phone (sandbox).', ['simulated' => 'declined']);
        }

        return new GatewayResult('succeeded', null, null, null, ['simulated' => 'approved']);
    }
}
