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

/** ENG-0001 — what actually happened, with a planned-vs-actual flag. */
class Engagement extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasFactory, HasReference, TracksAuthor;

    public const AUDIT_NAME = 'engagement';

    public const REFERENCE_KEY = 'engagement';

    public const PLANNED_VS_ACTUAL = ['on_plan', 'early', 'late', 'unplanned', 'missed', 'rescheduled', 'cancelled'];

    protected $fillable = [
        'organisation_id', 'project_id', 'client_uuid', 'engagement_plan_id', 'topic',
        'project_phase', 'location_id', 'location_text', 'latitude', 'longitude',
        'held_at', 'ended_at', 'method', 'venue', 'organised_by', 'facilitator_id',
        'aim', 'discussion_points', 'outcomes',
        'attendance_total', 'attendance_female', 'attendance_male', 'attendance_youth',
        'attendance_elderly', 'attendance_disability', 'attendance_vulnerable',
        'attendance_breakdown', 'vulnerable_groups_present', 'vulnerable_groups',
        'status', 'custom_fields', 'captured_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'held_at' => 'datetime',
            'ended_at' => 'datetime',
            'attendance_breakdown' => 'array',
            'vulnerable_groups' => 'array',
            'custom_fields' => 'array',
            'vulnerable_groups_present' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'captured_at' => 'datetime',
            'synced_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(EngagementPlan::class, 'engagement_plan_id');
    }

    public function stakeholders()
    {
        return $this->belongsToMany(Stakeholder::class, 'engagement_stakeholder')
            ->withPivot('attended')->withTimestamps();
    }

    public function participants()
    {
        return $this->hasMany(EngagementParticipant::class);
    }

    public function concerns()
    {
        return $this->hasMany(Concern::class);
    }

    public function commitments()
    {
        return $this->hasMany(Commitment::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function facilitator()
    {
        return $this->belongsTo(User::class, 'facilitator_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('topic', 'like', $like)
            ->orWhere('reference', 'like', $like)
            ->orWhere('venue', 'like', $like)
            ->orWhere('location_text', 'like', $like)
            ->orWhere('discussion_points', 'like', $like));
    }
}
