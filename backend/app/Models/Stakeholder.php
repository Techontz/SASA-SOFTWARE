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
 * MODULE 1 — the living register. The subject of every engagement and the
 * counterparty of every commitment.
 */
class Stakeholder extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasFactory, HasReference, TracksAuthor;

    public const AUDIT_NAME = 'stakeholder';

    public const REFERENCE_KEY = 'stakeholder';

    public const TYPES = [
        'individual', 'household', 'community_group', 'cso', 'government',
        'traditional_leader', 'contractor', 'project_staff', 'business', 'vulnerable_group',
    ];

    protected $fillable = [
        'organisation_id', 'project_id', 'client_uuid', 'name', 'alias', 'type', 'sub_type',
        'organisation_name', 'position', 'phone', 'alternate_phone', 'email',
        'preferred_language', 'preferred_contact_method', 'physical_address', 'postal_address',
        'primary_location_id', 'country', 'region', 'district', 'ward', 'village',
        'latitude', 'longitude', 'influence', 'interest', 'power', 'impact',
        'engagement_strategy', 'communication_frequency', 'concerns_expectations', 'notes',
        'is_vulnerable', 'vulnerability_categories', 'is_indigenous_or_minority',
        'consent_status', 'consent_basis', 'consent_date', 'identification_source',
        'identification_method', 'demographics', 'custom_fields', 'status',
        'review_date', 'owner_id', 'captured_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'vulnerability_categories' => 'array',
            'demographics' => 'array',
            'custom_fields' => 'array',
            'is_vulnerable' => 'boolean',
            'is_indigenous_or_minority' => 'boolean',
            'priority_overridden' => 'boolean',
            'consent_date' => 'date',
            'review_date' => 'date',
            'last_engaged_on' => 'date',
            'captured_at' => 'datetime',
            'synced_at' => 'datetime',
            'archived_at' => 'datetime',
            'priority_score' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function contacts()
    {
        return $this->hasMany(StakeholderContact::class);
    }

    public function assessments()
    {
        return $this->hasMany(StakeholderAssessment::class)->orderByDesc('assessed_at');
    }

    public function currentAssessment()
    {
        return $this->hasOne(StakeholderAssessment::class)->where('is_current', true);
    }

    public function primaryLocation()
    {
        return $this->belongsTo(Location::class, 'primary_location_id');
    }

    public function locations()
    {
        return $this->belongsToMany(Location::class, 'stakeholder_location')
            ->withPivot('relationship')->withTimestamps();
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function engagementPlans()
    {
        return $this->hasMany(EngagementPlan::class);
    }

    public function engagements()
    {
        return $this->belongsToMany(Engagement::class, 'engagement_stakeholder')
            ->withPivot('attended')->withTimestamps();
    }

    public function concerns()
    {
        return $this->hasMany(Concern::class);
    }

    public function grievances()
    {
        return $this->hasMany(Grievance::class);
    }

    public function commitments()
    {
        return $this->belongsToMany(Commitment::class, 'commitment_stakeholder')->withTimestamps();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function mergedInto()
    {
        return $this->belongsTo(Stakeholder::class, 'merged_into_id');
    }

    // ------------------------------------------------------------------ scopes

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('reference', 'like', $like)
                ->orWhere('alias', 'like', $like)
                ->orWhere('organisation_name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('village', 'like', $like)
                ->orWhere('ward', 'like', $like)
                ->orWhere('district', 'like', $like);
        });
    }

    public function scopeDueForReview(Builder $query): Builder
    {
        return $query->whereNotNull('review_date')->whereDate('review_date', '<=', today());
    }

    /** name + phone + village, normalised — the practical duplicate signal. */
    public static function duplicateHashFor(?string $name, ?string $phone, ?string $village): string
    {
        $normalise = fn (?string $value) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
        $digits = preg_replace('/\D/', '', (string) $phone);
        $tail = $digits !== '' ? substr($digits, -9) : '';

        return hash('sha256', $normalise($name).'|'.$tail.'|'.$normalise($village));
    }

    public function displayLocation(): string
    {
        return collect([$this->village, $this->ward, $this->district, $this->region])
            ->filter()->implode(', ') ?: ($this->primaryLocation?->path ?? '—');
    }
}
