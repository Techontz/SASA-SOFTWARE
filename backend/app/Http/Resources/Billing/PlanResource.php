<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A plan, as the public pricing page sees it. Prices are numbers or null. */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'price_usd' => $this->price_usd === null ? null : (float) $this->price_usd,
            'price_tzs' => $this->price_tzs === null ? null : (float) $this->price_tzs,
            'billing_period' => $this->billing_period,
            'features' => array_values((array) ($this->features ?? [])),
            'limits' => [
                'projects' => $this->limits['projects'] ?? null,
                'users' => $this->limits['users'] ?? null,
            ],
            'is_featured' => (bool) $this->is_featured,
            'is_custom_quote' => (bool) $this->is_custom_quote,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
