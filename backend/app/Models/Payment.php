<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt to pay for a subscription request. `raw` keeps every provider
 * payload for reconciliation; it is never returned by the API or audited.
 */
class Payment extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'payment';

    public const METHODS = ['mobile_money', 'card'];

    public const STATUSES = ['pending', 'succeeded', 'failed', 'cancelled'];

    protected array $auditExcluded = ['raw', 'checkout_url'];

    protected $hidden = ['raw'];

    protected $fillable = [
        'subscription_request_id', 'provider', 'method', 'phone', 'amount', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function subscriptionRequest()
    {
        return $this->belongsTo(SubscriptionRequest::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function auditReference(): ?string
    {
        return $this->public_id;
    }

    /** Append a provider payload under a key, keeping what is already there. */
    public function appendRaw(string $key, mixed $payload): void
    {
        $raw = $this->raw ?? [];
        $raw[$key][] = $payload;
        $this->raw = $raw;
    }
}
