<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Categories and subcategories are configuration. Retiring an entry hides it
 * from new cases but preserves it on historical ones.
 */
class GrievanceCategory extends Model
{
    use Auditable, HasFactory;

    public const AUDIT_NAME = 'grievance_category';

    protected $fillable = [
        'organisation_id', 'project_id', 'parent_id', 'key', 'name', 'description',
        'sort_order', 'default_severity', 'is_restricted', 'handling_groups',
        'aggregate_reporting_only', 'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'handling_groups' => 'array',
            'is_restricted' => 'boolean',
            'aggregate_reporting_only' => 'boolean',
            'retired_at' => 'datetime',
            'sort_order' => 'integer',
            'default_severity' => 'integer',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(GrievanceCategory::class, 'parent_id');
    }

    public function subcategories()
    {
        return $this->hasMany(GrievanceCategory::class, 'parent_id')->orderBy('sort_order');
    }

    public function grievances()
    {
        return $this->hasMany(Grievance::class, 'category_id');
    }

    /** Selectable on NEW cases. Retired entries stay visible on old ones. */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId));
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /** The restriction of a subcategory is inherited from its parent. */
    public function effectiveHandlingGroups(): ?array
    {
        if ($this->is_restricted && $this->handling_groups) {
            return $this->handling_groups;
        }

        if ($this->parent_id) {
            $parent = $this->relationLoaded('parent') ? $this->parent : $this->parent()->first();

            if ($parent?->is_restricted) {
                return $parent->handling_groups;
            }
        }

        return null;
    }

    public function isEffectivelyRestricted(): bool
    {
        if ($this->is_restricted) {
            return true;
        }

        $parent = $this->relationLoaded('parent') ? $this->parent : ($this->parent_id ? $this->parent()->first() : null);

        return (bool) $parent?->is_restricted;
    }
}
