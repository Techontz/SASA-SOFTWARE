<?php

namespace App\Http\Resources\Billing;

use App\Domain\Billing\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subscription request as the person who made it sees it. The
 * administrator's notes are not part of this shape at all.
 *
 * `charge_options` says what tapping Pay will charge for each method, so the
 * page can say "You will be charged TZS 380,000" before anyone pays.
 */
class SubscriptionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'reference' => $this->reference,
            'status' => $this->status,
            'currency' => $this->currency,
            'amount' => $this->amount === null ? null : (float) $this->amount,
            'plan' => new PlanResource($this->plan),
            'organisation_name' => $this->organisation_name,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'created_at' => $this->created_at?->toIso8601String(),
            'latest_payment' => $this->latestPayment ? $this->paymentResource($this->latestPayment) : null,
            'charge_options' => $this->status === 'pending_payment'
                ? app(PaymentService::class)->chargeOptions($this->resource)
                : null,
        ];
    }

    protected function paymentResource(mixed $payment): PaymentResource
    {
        return new PaymentResource($payment);
    }
}
