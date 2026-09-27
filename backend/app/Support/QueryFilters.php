<?php

namespace App\Support;

use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * One filtering vocabulary for every list view, so a saved view built on the
 * grievance list means the same thing when it is applied again next month.
 */
final class QueryFilters
{
    public static function apply(Builder $query, Request $request, array $allowed): Builder
    {
        foreach ($allowed as $key => $definition) {
            $field = is_int($key) ? $definition : $key;
            $handler = is_int($key) ? null : $definition;
            $value = $request->input($field);

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_callable($handler)) {
                $handler($query, $value);

                continue;
            }

            is_array($value)
                ? $query->whereIn($field, $value)
                : $query->where($field, $value);
        }

        return $query;
    }

    /** Cascading location filter: a district selection includes its wards. */
    public static function location(Builder $query, mixed $locationId, string $column = 'location_id'): Builder
    {
        $location = Location::find($locationId);

        return $query->whereIn($column, $location ? $location->descendantIds() : [(int) $locationId]);
    }

    public static function dateRange(Builder $query, Request $request, string $column, string $fromKey = 'from', string $toKey = 'to'): Builder
    {
        if ($request->filled($fromKey)) {
            $query->where($column, '>=', CarbonImmutable::parse($request->input($fromKey))->startOfDay());
        }

        if ($request->filled($toKey)) {
            $query->where($column, '<=', CarbonImmutable::parse($request->input($toKey))->endOfDay());
        }

        return $query;
    }

    /** @param array<int,string> $sortable */
    public static function sort(Builder $query, Request $request, array $sortable, string $default = '-created_at'): Builder
    {
        $sort = (string) $request->input('sort', $default);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-+');

        if (! in_array($column, $sortable, true)) {
            $column = ltrim($default, '-+');
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
        }

        return $query->orderBy($column, $direction)->orderBy($query->getModel()->getTable().'.id', $direction);
    }

    /**
     * A copy of the request with some inputs removed.
     *
     * List summaries use this so that filtering by status still shows the
     * open/closed split for everything else the user filtered to, rather than
     * collapsing to the filtered number.
     */
    public static function without(Request $request, array $keys): Request
    {
        $clone = new Request(collect($request->all())->except($keys)->all());
        $clone->setUserResolver($request->getUserResolver());

        return $clone;
    }

    public static function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        return min($max, max(1, (int) $request->input('per_page', $default)));
    }
}
