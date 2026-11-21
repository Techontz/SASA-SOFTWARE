<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * INV-2026-0001: one month of an agreement. The amount is copied from the
 * agreement when the invoice is issued, so a later price change only affects
 * invoices not yet issued. Addressed in URLs by its ULID.
 */
class Invoice extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'invoice';

    public const STATUSES = ['open', 'paid', 'overdue', 'void'];

    /** Statuses that can still be paid. */
    public const PAYABLE_STATUSES = ['open', 'overdue'];

    protected $fillable = [
        'organisation_id', 'organisation_agreement_id', 'period_start', 'period_end',
        'amount', 'currency', 'due_on',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_on' => 'date',
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function auditReference(): ?string
    {
        return $this->number;
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function agreement()
    {
        return $this->belongsTo(OrganisationAgreement::class, 'organisation_agreement_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany('id');
    }

    public function isPayable(): bool
    {
        return in_array($this->status, self::PAYABLE_STATUSES, true);
    }
}
