<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /** Only the projects this user actually belongs to. */
    public function index(Request $request)
    {
        $user = $request->user();

        $projects = $user->is_system_admin
            ? Project::with('organisation:id,name')->whereNull('archived_at')->orderBy('name')->get()
            : $user->activeMemberships()->get()->map(fn ($m) => $m->project->setRelation('membership_role', $m->role));

        return ApiResponse::data($projects->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'description' => $project->description,
            'country' => $project->country,
            'sector' => $project->sector,
            'status' => $project->status,
            'starts_on' => $project->starts_on?->toDateString(),
            'ends_on' => $project->ends_on?->toDateString(),
            'organisation' => $project->organisation?->only(['id', 'name']),
        ])->values());
    }

    public function show(Project $project)
    {
        abort_unless(
            $this->context()->isSystemAdmin() || $this->context()->projectId() === $project->id,
            404
        );

        return ApiResponse::data([
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'description' => $project->description,
            'country' => $project->country,
            'sector' => $project->sector,
            'timezone' => $project->timezone,
            'status' => $project->status,
            'starts_on' => $project->starts_on?->toDateString(),
            'ends_on' => $project->ends_on?->toDateString(),
            'fiscal_year_start_month' => $project->fiscal_year_start_month,
            'organisation' => $project->organisation?->only(['id', 'name', 'brand_color', 'country']),
            'counts' => [
                'stakeholders' => $project->stakeholders()->whereNull('archived_at')->count(),
                'grievances_open' => $project->grievances()->open()->count(),
                'engagements' => $project->engagements()->whereNull('archived_at')->count(),
                'commitments_open' => $project->commitments()->open()->count(),
                'members' => $project->memberships()->where('status', 'active')->count(),
            ],
            'my_role' => $this->context()->membership() ? [
                'key' => $this->context()->roleKey(),
                'name' => $this->context()->membership()->role->name,
                'permissions' => $this->context()->permissions(),
                'handling_groups' => $this->context()->handlingGroups(),
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->is_system_admin, 403, 'Only a system administrator can create a project.');

        $data = $request->validate([
            'organisation_id' => ['required', 'integer', 'exists:organisations,id'],
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9\-]+$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'country' => ['nullable', 'string', 'size:2'],
            'sector' => ['nullable', 'string', 'max:60'],
            'timezone' => ['nullable', 'string', 'max:60'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'fiscal_year_start_month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $project = Project::create($data);

        return ApiResponse::data($project, [], 201);
    }

    public function update(Request $request, Project $project)
    {
        abort_unless($this->context()->can('project.manage'), 403, 'You do not have permission to change project settings.');
        abort_unless($this->context()->projectId() === $project->id || $request->user()->is_system_admin, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'sector' => ['sometimes', 'nullable', 'string', 'max:60'],
            'timezone' => ['sometimes', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(['active', 'on_hold', 'closed'])],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'fiscal_year_start_month' => ['sometimes', 'integer', 'between:1,12'],
        ]);

        $project->update($data);

        return ApiResponse::data($project->fresh());
    }
}
