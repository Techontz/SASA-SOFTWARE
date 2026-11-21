<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;

/** An invoice for whoever holds billing.manage: whose it is, and the provider's reference. */
class AdminInvoiceResource extends InvoiceResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'organisation' => $this->organisation ? ['id' => $this->organisation->id, 'name' => $this->organisation->name] : null,
            'agreement' => $this->agreement ? ['public_id' => $this->agreement->public_id, 'name' => $this->agreement->name] : null,
            'latest_payment' => $this->latestPayment ? new AdminPaymentResource($this->latestPayment) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ]);
    }
}
