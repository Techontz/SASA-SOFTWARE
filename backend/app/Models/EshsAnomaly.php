<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasReference;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * ESA-0001 — an ESHS anomaly raised by the client or supervision team against
 * a contractor. The contractor answers it with closure evidence; only the
 * supervision team closes it.
 *
 * "Overdue" is never stored: it is a target date in the past on an anomaly
 * that is not closed, worked out whenever it is asked for.
 */
class EshsAnomaly extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasReference, TracksAuthor;

    public const AUDIT_NAME = 'eshs_anomaly';

    public const REFERENCE_KEY = 'eshs_anomaly';

    public const STATUSES = ['open', 'in_progress', 'closed'];

    public const OPEN_STATUSES = ['open', 'in_progress'];

    public const KIND_EVIDENCE = 'evidence';

    public const KIND_CLOSURE_EVIDENCE = 'closure_evidence';

    protected $table = 'eshs_anomalies';

    protected $fillable = [
        'organisation_id', 'project_id', 'location_id', 'raised_on', 'contractor', 'subcontractor',
        'category', 'subcategory', 'description', 'anomaly_type', 'risk_rating',
        'root_cause_group', 'root_cause', 'contractor_action', 'action_owner_name',
        'action_owner_email', 'action_owner_phone', 'target_date', 'progress_notes',
    ];

    protected function casts(): array
    {
        return [
            'raised_on' => 'date',
            'target_date' => 'date',
            'closed_on' => 'date',
            'evidence_submitted_at' => 'datetime',
            'returned_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** Archiving keeps the workflow status: an archived closed anomaly was still closed. */
    public function archive(?string $reason = null): void
    {
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function raiser()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'evidence_submitted_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function scopeNotClosed(Builder $query): Builder
    {
        return $query->whereIn($this->getTable().'.status', self::OPEN_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn($this->getTable().'.status', self::OPEN_STATUSES)
            ->whereNotNull($this->getTable().'.target_date')
            ->whereDate($this->getTable().'.target_date', '<', today());
    }

    public function scopeAwaitingVerification(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', 'in_progress')
            ->whereNotNull($this->getTable().'.evidence_submitted_at');
    }

    /**
     * The contractor a viewer is limited to, or null for no limit. A member
     * on the contractor role with no contractor assigned sees nothing rather
     * than everything: the safe failure is an empty list, not a leak.
     */
    public static function contractorScopeFor(?ProjectMembership $membership, ?string $roleKey): ?string
    {
        $contractor = $membership?->contractor_name;

        if ($contractor !== null && $contractor !== '') {
            return $contractor;
        }

        return $roleKey === 'contractor' ? self::NO_CONTRACTOR : null;
    }

    /** Matches no real contractor name. */
    public const NO_CONTRACTOR = "\0unassigned";

    /** Contractor personnel see only their own company's anomalies. */
    public function scopeForContractor(Builder $query, ?string $contractor): Builder
    {
        return $contractor === null || $contractor === ''
            ? $query
            : $query->where($this->getTable().'.contractor', $contractor);
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isAwaitingVerification(): bool
    {
        return $this->status === 'in_progress' && $this->evidence_submitted_at !== null;
    }

    public function isOverdue(): bool
    {
        return $this->target_date !== null
            && ! $this->isClosed()
            && $this->target_date->lt(today());
    }

    public function daysUntilTarget(): ?int
    {
        return $this->target_date ? (int) today()->diffInDays($this->target_date, false) : null;
    }

    /** open | in_progress | awaiting_verification | closed — what the UI shows. */
    public function stage(): string
    {
        return $this->isAwaitingVerification() ? 'awaiting_verification' : (string) $this->status;
    }

    public function closureEvidenceCount(): int
    {
        return $this->attachments()
            ->where('kind', self::KIND_CLOSURE_EVIDENCE)
            ->whereNull('archived_at')
            ->count();
    }
}
