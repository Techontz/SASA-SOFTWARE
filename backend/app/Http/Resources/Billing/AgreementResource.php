<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full agreement for whoever holds billing.manage, including the SASA
 * team's internal note. Never used for a customer: see CustomerAgreementResource.
 */
class AgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'organisation' => $this->organisation ? ['id' => $this->organisation->id, 'name' => $this->organisation->name] : null,
            'enquiry_id' => $this->enquiry_id,
            'name' => $this->name,
            'currency' => $this->currency,
            'monthly_amount' => (float) $this->monthly_amount,
            'billing_day' => $this->billing_day,
            'starts_on' => $this->starts_on?->toDateString(),
            'next_due_on' => $this->next_due_on?->toDateString(),
            'status' => $this->status,
            'modules' => $this->modules ?? [],
            'limits' => $this->normalisedLimits(),
            'terms_note' => $this->terms_note,
            'internal_note' => $this->internal_note,
            'auto_pay' => (bool) $this->auto_pay,
            'auto_pay_phone' => $this->auto_pay_phone,
            'created_by' => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
