<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The agreed plan as the organisation itself sees it. The SASA team's
 * internal note and the enquiry it came from are never part of it: the keys
 * are absent, not null.
 *
 * Pass the usage as ['agreement' => $model, 'usage' => [...]].
 */
class CustomerAgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $agreement = $this->resource['agreement'];

        return [
            'public_id' => $agreement->public_id,
            'name' => $agreement->name,
            'currency' => $agreement->currency,
            'monthly_amount' => (float) $agreement->monthly_amount,
            'billing_day' => $agreement->billing_day,
            'starts_on' => $agreement->starts_on?->toDateString(),
            'next_due_on' => $agreement->next_due_on?->toDateString(),
            'status' => $agreement->status,
            'modules' => $agreement->modules ?? [],
            'limits' => $agreement->normalisedLimits(),
            'terms_note' => $agreement->terms_note,
            'auto_pay' => (bool) $agreement->auto_pay,
            'auto_pay_phone' => $agreement->auto_pay_phone,
            'usage' => $this->resource['usage'],
        ];
    }
}
