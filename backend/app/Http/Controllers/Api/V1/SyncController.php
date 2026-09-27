<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Configuration\ConfigurationRegistry;
use App\Domain\Sync\SyncService;
use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\Location;
use App\Models\Stakeholder;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The device end of the offline contract.
 *
 *   POST /sync/push  — replay the local queue (idempotent, resumable)
 *   GET  /sync/pull  — everything changed since the device last synced
 *   GET  /sync/bootstrap — the reference data a device needs to work offline
 */
class SyncController extends Controller
{
    public function __construct(private readonly SyncService $sync) {}

    public function push(Request $request)
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
            'operations' => ['required', 'array', 'min:1', 'max:'.config('sasa.sync.max_batch_operations', 200)],
            'operations.*.operation_uuid' => ['required', 'uuid'],
            'operations.*.entity' => ['required', 'string', 'max:40'],
            'operations.*.operation' => ['required', Rule::in(['create', 'update'])],
            'operations.*.entity_uuid' => ['nullable', 'uuid'],
            'operations.*.server_id' => ['nullable', 'integer'],
            'operations.*.payload' => ['required', 'array'],
            'operations.*.base_values' => ['nullable', 'array'],
            'operations.*.base_updated_at' => ['nullable', 'date'],
            'operations.*.client_created_at' => ['nullable', 'date'],
            'operations.*.retry_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $result = $this->sync->push($this->project(), $data['device_id'], $data['operations']);

        return ApiResponse::data($result);
    }

    public function pull(Request $request)
    {
        $request->validate([
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'between:1,1000'],
        ]);

        $since = $request->filled('since') ? CarbonImmutable::parse($request->input('since')) : null;

        return ApiResponse::data($this->sync->pull(
            $this->project(),
            $since,
            (int) $request->input('limit', 500)
        ));
    }

    /**
     * The reference data a field device needs before going offline: the
     * category tree, the location hierarchy, project members and the
     * configuration lists the forms are built from.
     */
    public function bootstrap(Request $request)
    {
        $project = $this->project();

        return ApiResponse::data([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'country' => $project->country,
                'timezone' => $project->timezone,
            ],
            'server_time' => now()->toIso8601String(),
            'categories' => GrievanceCategory::forProject($project->id)
                ->selectable()->topLevel()
                ->with(['subcategories' => fn ($q) => $q->selectable()])
                ->orderBy('sort_order')->get()
                ->map(fn ($category) => [
                    'id' => $category->id,
                    'key' => $category->key,
                    'name' => $category->name,
                    'default_severity' => $category->default_severity,
                    'is_restricted' => $category->is_restricted,
                    'subcategories' => $category->subcategories->map(fn ($sub) => [
                        'id' => $sub->id, 'key' => $sub->key, 'name' => $sub->name,
                    ]),
                ]),
            'locations' => Location::visibleToProject($project->id)
                ->where('organisation_id', $project->organisation_id)
                ->where('status', 'active')
                ->orderBy('path')
                ->get(['id', 'parent_id', 'level', 'name', 'path', 'latitude', 'longitude']),
            'members' => $project->members()
                ->where('project_memberships.status', 'active')
                ->get(['users.id', 'users.name'])
                ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name]),
            'severity_levels' => collect(config('sasa.grievances.severity_levels'))
                ->map(fn ($level, $key) => ['level' => (int) $key, 'label' => $level['label'], 'description' => $level['description']])
                ->values(),
            'stakeholder_types' => Stakeholder::TYPES,
            'channels' => Grievance::CHANNELS,
            'configuration' => app(ConfigurationRegistry::class)
                ->all($project->organisation_id, $project->id),
        ]);
    }

    public function conflicts(Request $request)
    {
        abort_unless($this->context()->can('sync.resolve_conflicts'), 403, 'You do not have permission to resolve sync conflicts.');

        $conflicts = SyncConflict::query()
            ->where('project_id', $this->project()->id)
            ->when($request->input('status', 'open') !== 'all', fn ($q) => $q->where('status', $request->input('status', 'open')))
            ->with(['operation:id,device_id,user_id,created_at', 'resolver:id,name'])
            ->orderByDesc('created_at')
            ->paginate(25);

        return ApiResponse::data($conflicts);
    }

    public function resolveConflict(Request $request, SyncConflict $conflict)
    {
        abort_unless($this->context()->can('sync.resolve_conflicts'), 403, 'You do not have permission to resolve sync conflicts.');
        abort_unless($conflict->project_id === $this->project()->id, 404);

        $data = $request->validate([
            'resolution' => ['required', Rule::in(['keep_local', 'keep_server', 'merged'])],
            'values' => ['nullable', 'array'],
        ]);

        $resolved = $this->sync->resolveConflict($conflict, $data['resolution'], $data['values'] ?? []);

        return ApiResponse::data($resolved);
    }

    /** What the device queue looks like from the server's side. */
    public function status(Request $request)
    {
        $deviceId = $request->input('device_id');

        $query = SyncOperation::query()
            ->where('project_id', $this->project()->id)
            ->where('user_id', $request->user()->id)
            ->when($deviceId, fn ($q) => $q->where('device_id', $deviceId));

        return ApiResponse::data([
            'server_time' => now()->toIso8601String(),
            'device_id' => $deviceId,
            'counts' => [
                'applied' => (clone $query)->where('status', 'applied')->count(),
                'conflict' => (clone $query)->where('status', 'conflict')->count(),
                'rejected' => (clone $query)->where('status', 'rejected')->count(),
            ],
            'open_conflicts' => SyncConflict::where('project_id', $this->project()->id)
                ->where('status', 'open')->count(),
            'recent' => (clone $query)->latest()->limit(20)
                ->get(['operation_uuid', 'entity', 'operation', 'status', 'server_reference', 'error', 'processed_at']),
        ]);
    }
}
