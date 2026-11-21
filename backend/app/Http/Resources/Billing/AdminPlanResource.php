<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;

/** The plan for whoever manages billing: the public shape plus its lifecycle. */
class AdminPlanResource extends PlanResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'is_archived' => $this->archived_at !== null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
