<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment attempt. `amount` and `currency` are what is actually charged,
 * which can differ from the request (Snippe settles in TZS only). The
 * provider's raw payloads are never part of the response.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'method' => $this->method,
            'status' => $this->status,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'phone' => $this->phone,
            'checkout_url' => $this->checkout_url,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
