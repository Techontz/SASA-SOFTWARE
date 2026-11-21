<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan. Platform level, edited by whoever holds billing.manage.
 * "Delete" archives it: requests already made against it keep pointing at it.
 */
class Plan extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'plan';

    public const PERIODS = ['monthly', 'yearly'];

    protected $fillable = [
        'code', 'name', 'tagline', 'description', 'price_usd', 'price_tzs',
        'billing_period', 'features', 'limits', 'is_featured', 'is_custom_quote', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_usd' => 'decimal:2',
            'price_tzs' => 'decimal:2',
            'features' => 'array',
            'limits' => 'array',
            'is_featured' => 'boolean',
            'is_custom_quote' => 'boolean',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('plans.archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** The plan's price in a currency, or null when it has none. */
    public function priceIn(string $currency): ?string
    {
        $value = match (strtoupper($currency)) {
            'USD' => $this->price_usd,
            'TZS' => $this->price_tzs,
            default => null,
        };

        return $value === null ? null : (string) $value;
    }

    public function auditReference(): ?string
    {
        return $this->code;
    }
}
