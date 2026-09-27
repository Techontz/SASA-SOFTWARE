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

/** PLAN-0001 — the upstream schedule the engagement log tracks against. */
class EngagementPlan extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasFactory, HasReference, TracksAuthor;

    public const AUDIT_NAME = 'engagement_plan';

    public const REFERENCE_KEY = 'engagement_plan';

    public const STATUSES = ['planned', 'completed', 'rescheduled', 'postponed', 'cancelled', 'missed'];

    protected $fillable = [
        'organisation_id', 'project_id', 'client_uuid', 'title', 'project_phase',
        'stakeholder_id', 'stakeholder_group', 'purpose', 'method', 'target_date',
        'window_start', 'window_end', 'location_id', 'location_text',
        'vulnerable_group_accommodation', 'accommodation_notes', 'fpic_required', 'fpic_notes',
        'grievance_channel_available', 'owner_id', 'responsible_team', 'priority',
        'recurrence', 'recurrence_until', 'recurrence_parent_id', 'status', 'status_reason',
        'budget_amount', 'budget_currency', 'resources_required', 'custom_fields',
        'captured_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'window_start' => 'date',
            'window_end' => 'date',
            'recurrence_until' => 'date',
            'vulnerable_group_accommodation' => 'boolean',
            'fpic_required' => 'boolean',
            'grievance_channel_available' => 'boolean',
            'custom_fields' => 'array',
            'budget_amount' => 'decimal:2',
            'captured_at' => 'datetime',
            'synced_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function stakeholder()
    {
        return $this->belongsTo(Stakeholder::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function engagements()
    {
        return $this->hasMany(Engagement::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** Planned but past its window with nothing logged against it. */
    public function scopeMissed(Builder $query): Builder
    {
        return $query->where('status', 'planned')
            ->whereNotNull('target_date')
            ->whereDate('target_date', '<', today())
            ->whereDoesntHave('engagements');
    }

    public function scopeUpcoming(Builder $query, int $days = 30): Builder
    {
        return $query->where('status', 'planned')
            ->whereBetween('target_date', [today(), today()->addDays($days)]);
    }

    public function effectiveWindow(): array
    {
        return [
            $this->window_start ?? $this->target_date,
            $this->window_end ?? $this->target_date,
        ];
    }
}
