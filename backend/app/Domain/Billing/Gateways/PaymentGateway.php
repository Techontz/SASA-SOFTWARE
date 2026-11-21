<?php

namespace App\Domain\Billing\Gateways;

use App\Models\Payment;
use App\Models\SubscriptionRequest;

/**
 * A payment provider. The application is never written around one vendor:
 * the driver is chosen by `sasa.billing.driver`.
 */
interface PaymentGateway
{
    /** 'sandbox' | 'snippe', stored on the payment as its provider. */
    public function name(): string;

    /** Send a USSD push to `$payment->phone`. Must not throw for a refusal. */
    public function startMobileMoney(Payment $payment, SubscriptionRequest $request): GatewayResult;

    /** Create a hosted card checkout. The result carries the checkout URL. */
    public function startCard(Payment $payment, SubscriptionRequest $request): GatewayResult;

    /** The provider's current view of a pending payment, or null when it cannot say. */
    public function fetchStatus(Payment $payment): ?GatewayResult;
}
