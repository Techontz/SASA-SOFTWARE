<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The plan the SASA team agreed with one organisation: a monthly price, the
 * modules it covers and its limits. Prices are never public; each company
 * gets its own agreement. Addressed in URLs by its ULID.
 *
 * At most one agreement per organisation is "current" (not archived, not
 * cancelled). `internal_note` is for the SASA team only and is never part of
 * a customer response.
 */
class OrganisationAgreement extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'agreement';

    public const STATUSES = ['active', 'past_due', 'suspended', 'cancelled'];

    /** The statuses an administrator may set by hand. past_due comes from the billing cycle. */
    public const ADMIN_STATUSES = ['active', 'suspended', 'cancelled'];

    /** Statuses the billing cycle raises invoices for. */
    public const BILLABLE_STATUSES = ['active', 'past_due'];

    public const MODULES = [
        'stakeholder_register', 'stakeholder_engagement', 'grievance_management',
        'pap_register', 'incident_notification', 'eshs_assurance', 'dashboards_reporting',
    ];

    protected $fillable = [
        'organisation_id', 'enquiry_id', 'name', 'currency', 'monthly_amount', 'billing_day',
        'starts_on', 'next_due_on', 'modules', 'limits', 'terms_note', 'internal_note',
    ];

    protected function casts(): array
    {
        return [
            'monthly_amount' => 'decimal:2',
            'billing_day' => 'integer',
            'starts_on' => 'date',
            'next_due_on' => 'date',
            'modules' => 'array',
            'limits' => 'array',
            'auto_pay' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function auditReference(): ?string
    {
        return $this->public_id;
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function enquiry()
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Not archived and not cancelled: the one that counts for the organisation. */
    public function scopeCurrent($query)
    {
        return $query->whereNull('archived_at')->where('status', '!=', 'cancelled');
    }

    /** @return array{projects:int|null,users:int|null} */
    public function normalisedLimits(): array
    {
        $limits = (array) ($this->limits ?? []);

        return [
            'projects' => isset($limits['projects']) ? (int) $limits['projects'] : null,
            'users' => isset($limits['users']) ? (int) $limits['users'] : null,
        ];
    }
}
