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
 * INC-0001 — an Incident Notification Report. Raised from the field with
 * evidence, then reviewed and closed by someone holding incident.manage.
 *
 * "Serious" is derived, never stored: potential consequence at or above the
 * configured level, or a type flagged serious (Fatality, Permanent Disability).
 */
class Incident extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasReference, TracksAuthor;

    public const AUDIT_NAME = 'incident';

    public const REFERENCE_KEY = 'incident';

    public const STATUSES = ['submitted', 'under_review', 'closed'];

    protected $fillable = [
        'client_uuid', 'occurred_at', 'company', 'location_id', 'site_detail',
        'category', 'incident_type', 'actual_consequence', 'potential_consequence',
        'summary', 'contractor_manager', 'resident_engineer',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'actual_consequence' => 'integer',
            'potential_consequence' => 'integer',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** @return array<int,string> */
    public static function seriousTypes(): array
    {
        return collect(config('sasa.incidents.types', []))
            ->filter(fn ($type) => ! empty($type['serious']))
            ->pluck('key')
            ->values()
            ->all();
    }

    public static function seriousLevel(): int
    {
        return (int) config('sasa.incidents.serious_potential_level', 4);
    }

    public function isSerious(): bool
    {
        return (int) $this->potential_consequence >= self::seriousLevel()
            || in_array($this->incident_type, self::seriousTypes(), true);
    }

    public function scopeSerious(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('potential_consequence', '>=', self::seriousLevel())
            ->orWhereIn('incident_type', self::seriousTypes() ?: ['']));
    }

    public function scopeNotSerious(Builder $query): Builder
    {
        return $query->where('potential_consequence', '<', self::seriousLevel())
            ->whereNotIn('incident_type', self::seriousTypes() ?: ['']);
    }
}
