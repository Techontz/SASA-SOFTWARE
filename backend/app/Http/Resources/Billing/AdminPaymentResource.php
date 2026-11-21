<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;

/** For reconciliation: which provider, and its reference. Still no raw payloads. */
class AdminPaymentResource extends PaymentResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
