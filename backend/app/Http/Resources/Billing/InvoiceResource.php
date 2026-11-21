<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** An invoice as the organisation sees it, with its most recent payment attempt. */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'number' => $this->number,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'due_on' => $this->due_on?->toDateString(),
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'latest_payment' => $this->latestPayment ? new PaymentResource($this->latestPayment) : null,
        ];
    }
}
