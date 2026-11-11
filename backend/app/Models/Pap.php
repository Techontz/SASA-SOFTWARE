<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasReference;
use App\Models\Concerns\TracksAuthor;
use App\Models\Pap\PapAsset;
use App\Models\Pap\PapCrop;
use App\Models\Pap\PapDocument;
use App\Models\Pap\PapEntitlement;
use App\Models\Pap\PapHouseholdSurvey;
use App\Models\Pap\PapLandParcel;
use App\Models\Pap\PapLivelihood;
use App\Models\Pap\PapPayment;
use App\Models\Pap\PapRelocation;
use App\Models\Pap\PapValuation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * PAP-0001 — a Project Affected Person. The profile (Part A of the register);
 * everything the project owes them hangs off it as child records.
 *
 * The PAP is mirrored into the stakeholder register as a stakeholder of type
 * "pap" by PapService. The National ID never crosses over.
 */
class Pap extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasReference, TracksAuthor;

    public const AUDIT_NAME = 'pap';

    public const REFERENCE_KEY = 'pap';

    /** Absent from every response unless the viewer holds pap.view_sensitive. */
    public const SENSITIVE_FIELDS = ['national_id'];

    /** The Part A fields a person types in. */
    public const INPUT_FIELDS = [
        'client_uuid', 'household_id', 'location_id', 'kitongoji', 'full_name', 'national_id',
        'gender', 'date_of_birth', 'stated_age', 'marital_status', 'phone', 'alternative_contact',
        'physical_address', 'latitude', 'longitude', 'next_of_kin', 'next_of_kin_contact',
        'vulnerability_category', 'remarks',
    ];

    protected $fillable = [
        'organisation_id', 'project_id', 'client_uuid', 'household_id', 'stakeholder_id',
        'location_id', 'region', 'district', 'ward', 'village', 'kitongoji', 'full_name',
        'national_id', 'gender', 'date_of_birth', 'stated_age', 'marital_status', 'phone',
        'alternative_contact', 'physical_address', 'latitude', 'longitude', 'next_of_kin',
        'next_of_kin_contact', 'vulnerability_category', 'remarks', 'status', 'captured_at', 'synced_at',
    ];

    protected array $auditExcluded = ['national_id'];

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'date_of_birth' => 'date',
            'stated_age' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
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

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function householdSurveys()
    {
        return $this->hasMany(PapHouseholdSurvey::class);
    }

    public function landParcels()
    {
        return $this->hasMany(PapLandParcel::class);
    }

    public function assets()
    {
        return $this->hasMany(PapAsset::class);
    }

    public function crops()
    {
        return $this->hasMany(PapCrop::class);
    }

    public function valuations()
    {
        return $this->hasMany(PapValuation::class);
    }

    public function entitlements()
    {
        return $this->hasMany(PapEntitlement::class);
    }

    public function payments()
    {
        return $this->hasMany(PapPayment::class);
    }

    public function relocations()
    {
        return $this->hasMany(PapRelocation::class);
    }

    public function livelihoods()
    {
        return $this->hasMany(PapLivelihood::class);
    }

    public function documents()
    {
        return $this->hasMany(PapDocument::class);
    }

    public function scopeVulnerable(Builder $query): Builder
    {
        return $query->whereNotNull('vulnerability_category')->where('vulnerability_category', '!=', 'none');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('full_name', 'like', $like)
            ->orWhere('reference', 'like', $like)
            ->orWhere('household_id', 'like', $like)
            ->orWhere('phone', 'like', $like)
            ->orWhere('village', 'like', $like)
            ->orWhere('kitongoji', 'like', $like));
    }

    /** Age is derived from the date of birth; a stated age is the fallback. */
    public function age(): ?int
    {
        if ($this->date_of_birth) {
            return (int) $this->date_of_birth->diffInYears(today());
        }

        return $this->stated_age;
    }

    public function isVulnerable(): bool
    {
        return $this->vulnerability_category !== null && $this->vulnerability_category !== 'none';
    }

    public function displayLocation(): string
    {
        return collect([$this->kitongoji, $this->village, $this->ward, $this->district, $this->region])
            ->filter()->implode(', ');
    }
}
