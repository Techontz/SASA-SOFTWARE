<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasReference;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * COM-0001 — the accountability tail of an engagement. A promise made at a
 * village meeting in March that must still be traceable in October.
 */
class Commitment extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasFactory, HasReference, TracksAuthor;

    public const AUDIT_NAME = 'commitment';

    public const REFERENCE_KEY = 'commitment';

    public const STATUSES = ['open', 'in_progress', 'overdue', 'fulfilled', 'cancelled'];

    protected $fillable = [
        'organisation_id', 'project_id', 'client_uuid', 'engagement_id', 'concern_id',
        'grievance_id', 'location_id', 'commitment_text', 'source_type', 'source_date',
        'owner_id', 'owner_team', 'due_date', 'priority', 'risk_level', 'status',
        'completed_on', 'evidence_notes', 'verification_status', 'notes',
        'custom_fields', 'captured_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'source_date' => 'date',
            'due_date' => 'date',
            'completed_on' => 'date',
            'verified_at' => 'datetime',
            'custom_fields' => 'array',
            'reminders_sent' => 'array',
            'captured_at' => 'datetime',
            'synced_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function engagement()
    {
        return $this->belongsTo(Engagement::class);
    }

    public function concern()
    {
        return $this->belongsTo(Concern::class);
    }

    public function grievance()
    {
        return $this->belongsTo(Grievance::class);
    }

    public function stakeholders()
    {
        return $this->belongsToMany(Stakeholder::class, 'commitment_stakeholder')->withTimestamps();
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'in_progress', 'overdue']);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'in_progress', 'overdue'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today());
    }

    public function scopeUpcoming(Builder $query, int $days = 30): Builder
    {
        return $query->whereIn('status', ['open', 'in_progress'])
            ->whereBetween('due_date', [today(), today()->addDays($days)]);
    }

    public function scopeHighRisk(Builder $query): Builder
    {
        return $query->where('risk_level', 'high');
    }

    public function scopeUnverified(Builder $query): Builder
    {
        return $query->where('status', 'fulfilled')->where('verification_status', 'unverified');
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && in_array($this->status, ['open', 'in_progress', 'overdue'], true);
    }

    public function daysUntilDue(): ?int
    {
        return $this->due_date ? (int) today()->diffInDays($this->due_date, false) : null;
    }
}
