<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;

/** The full request for whoever manages billing, with every payment attempt. */
class AdminSubscriptionRequestResource extends SubscriptionRequestResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'country' => $this->country,
            'message' => $this->message,
            'admin_notes' => $this->admin_notes,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'payments' => $this->whenLoaded('payments', fn () => AdminPaymentResource::collection($this->payments)),
        ];
    }

    protected function paymentResource(mixed $payment): PaymentResource
    {
        return new AdminPaymentResource($payment);
    }
}
