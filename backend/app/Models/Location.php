<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** country > region > district > ward > village */
class Location extends Model
{
    use Auditable, HasFactory;

    public const AUDIT_NAME = 'location';

    public const LEVELS = ['country', 'region', 'district', 'ward', 'village'];

    protected $fillable = [
        'organisation_id', 'project_id', 'parent_id', 'level', 'name', 'code',
        'path', 'latitude', 'longitude', 'estimated_population', 'population_profile', 'status',
    ];

    protected function casts(): array
    {
        return [
            'population_profile' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'estimated_population' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Location $location) {
            if ($location->isDirty(['parent_id', 'name'])) {
                $location->path = $location->buildPath();
            }
        });
    }

    public function buildPath(): string
    {
        $segments = [$this->name];
        $parent = $this->parent_id ? static::find($this->parent_id) : null;
        $guard = 0;

        while ($parent && $guard++ < 10) {
            array_unshift($segments, $parent->name);
            $parent = $parent->parent_id ? static::find($parent->parent_id) : null;
        }

        return implode(' / ', $segments);
    }

    public function parent()
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    public function descendants()
    {
        return $this->children()->with('descendants');
    }

    /** Every id at or below this location — the cascading location filter. */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = static::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    public function scopeLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', $level);
    }

    public function scopeVisibleToProject(Builder $query, int $projectId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId));
    }
}
