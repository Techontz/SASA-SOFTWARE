<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        $query = Location::query()
            ->where('organisation_id', $this->project()->organisation_id)
            ->visibleToProject($this->project()->id)
            ->where('status', 'active');

        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->input('parent_id'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        return ApiResponse::data(
            $query->orderBy('path')->limit(2000)
                ->get(['id', 'parent_id', 'level', 'name', 'code', 'path', 'latitude', 'longitude', 'estimated_population'])
        );
    }

    /** The full tree, for the cascading location picker. */
    public function tree()
    {
        $locations = Location::query()
            ->where('organisation_id', $this->project()->organisation_id)
            ->visibleToProject($this->project()->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'level', 'name', 'path']);

        $byParent = $locations->groupBy('parent_id');

        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent[$parentId] ?? collect())->map(fn ($location) => [
                'id' => $location->id,
                'name' => $location->name,
                'level' => $location->level,
                'path' => $location->path,
                'children' => $build($location->id),
            ])->values();
        };

        return ApiResponse::data($build(null));
    }

    public function store(Request $request)
    {
        abort_unless($this->context()->can('location.manage'), 403, 'You do not have permission to manage locations.');

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:locations,id'],
            'level' => ['required', Rule::in(Location::LEVELS)],
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'estimated_population' => ['nullable', 'integer', 'min:0'],
            'population_profile' => ['nullable', 'array'],
            'shared' => ['nullable', 'boolean'],
        ]);

        $location = Location::create(array_merge($data, [
            'organisation_id' => $this->project()->organisation_id,
            'project_id' => ($data['shared'] ?? false) ? null : $this->project()->id,
            'status' => 'active',
        ]));

        return ApiResponse::data($location, [], 201);
    }

    public function update(Request $request, Location $location)
    {
        abort_unless($this->context()->can('location.manage'), 403, 'You do not have permission to manage locations.');
        abort_unless($location->organisation_id === $this->project()->organisation_id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'estimated_population' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'population_profile' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $location->update($data);

        return ApiResponse::data($location->fresh());
    }
}
