<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * SUB-2026-0001: someone asking to subscribe to a plan. Made by the public, so
 * it is addressed in URLs by an unguessable ULID, never by its numeric id.
 */
class SubscriptionRequest extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'subscription_request';

    public const STATUSES = ['pending_payment', 'paid', 'quote_requested', 'active', 'rejected', 'cancelled'];

    /** The statuses an administrator may set. */
    public const REVIEW_STATUSES = ['active', 'rejected', 'cancelled'];

    protected $fillable = [
        'plan_id', 'currency', 'amount', 'organisation_name', 'contact_name',
        'email', 'phone', 'country', 'message',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany('id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
