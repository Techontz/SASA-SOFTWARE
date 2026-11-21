<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** An organisation's current agreement, at a glance, for the platform administrator's list. */
class AgreementSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'currency' => $this->currency,
            'monthly_amount' => (float) $this->monthly_amount,
            'billing_day' => $this->billing_day,
            'next_due_on' => $this->next_due_on?->toDateString(),
            'status' => $this->status,
            'auto_pay' => (bool) $this->auto_pay,
        ];
    }
}
