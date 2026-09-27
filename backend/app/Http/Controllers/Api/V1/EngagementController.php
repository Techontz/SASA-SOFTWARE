<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Engagement\EngagementService;
use App\Domain\Export\ExportService;
use App\Http\Controllers\Controller;
use App\Http\Resources\EngagementResource;
use App\Models\Engagement;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EngagementController extends Controller
{
    public function __construct(private readonly EngagementService $engagements) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Engagement::class);

        $query = $this->buildQuery($request)
            ->with(['plan:id,reference,target_date,status', 'location:id,name,path', 'facilitator:id,name'])
            ->withCount(['concerns', 'commitments', 'participants', 'attachments']);

        QueryFilters::sort($query, $request, ['held_at', 'reference', 'topic', 'attendance_total', 'created_at'], '-held_at');

        return EngagementResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        )->additional(['meta' => ['summary' => $this->summary($request)]]);
    }

    /**
     * Logging an engagement can create its concerns and commitments in the
     * same submission — which is the whole point of the chain.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Engagement::class);

        $engagement = $this->engagements->logEngagement($this->project(), $this->validated($request));

        return ApiResponse::data(
            new EngagementResource($engagement->load(['plan', 'stakeholders', 'participants', 'concerns', 'commitments'])),
            [],
            201
        );
    }

    public function show(Engagement $engagement)
    {
        $this->authorize('view', $engagement);

        $engagement->load([
            'plan', 'location', 'facilitator:id,name', 'stakeholders:id,reference,name,type',
            'participants', 'concerns.category', 'commitments.owner:id,name',
            'attachments.uploader:id,name',
        ]);

        return ApiResponse::data(new EngagementResource($engagement));
    }

    public function update(Request $request, Engagement $engagement)
    {
        $this->authorize('update', $engagement);

        $updated = $this->engagements->updateEngagement($engagement, $this->validated($request, updating: true));

        return ApiResponse::data(new EngagementResource($updated));
    }

    public function archive(Engagement $engagement)
    {
        $this->authorize('archive', $engagement);

        $engagement->archive();

        return ApiResponse::message("{$engagement->reference} has been archived.");
    }

    public function export(Request $request, ExportService $exports)
    {
        $this->authorize('viewAny', Engagement::class);

        $result = $exports->export(
            project: $this->project(),
            user: $request->user(),
            entity: 'engagements',
            format: $request->input('format', 'xlsx'),
            query: $this->buildQuery($request)->with(['plan:id,reference,target_date', 'location:id,path']),
            shape: [
                'columns' => ['ID', 'Date', 'Topic', 'Method', 'Location', 'Plan', 'Planned for', 'Planned vs actual', 'Variance (days)', 'Attendance', 'Female', 'Vulnerable'],
                'mapper' => fn (Engagement $e) => [
                    $e->reference, $e->held_at?->toDateString(), $e->topic, $e->method,
                    $e->location?->path ?? $e->location_text,
                    $e->plan?->reference ?? 'Unplanned', $e->plan?->target_date?->toDateString(),
                    str_replace('_', ' ', (string) $e->planned_vs_actual), $e->variance_days,
                    $e->attendance_total, $e->attendance_female, $e->attendance_vulnerable,
                ],
            ],
            filters: $request->except(['page', 'per_page', 'format']),
        );

        return Storage::disk(config('filesystems.default', 'local'))->download($result['path'], $result['filename']);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'engagement_plan_id' => ['nullable', 'integer', 'exists:engagement_plans,id'],
            'topic' => [$required, 'string', 'max:255'],
            'project_phase' => ['nullable', 'string', 'max:60'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'held_at' => [$required, 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:held_at'],
            'method' => ['nullable', 'string', 'max:60'],
            'venue' => ['nullable', 'string', 'max:255'],
            'organised_by' => ['nullable', 'string', 'max:255'],
            'facilitator_id' => ['nullable', 'integer', 'exists:users,id'],
            'aim' => ['nullable', 'string', 'max:5000'],
            'discussion_points' => ['nullable', 'string', 'max:50000'],
            'outcomes' => ['nullable', 'string', 'max:50000'],

            'attendance_total' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'attendance_female' => ['nullable', 'integer', 'min:0'],
            'attendance_male' => ['nullable', 'integer', 'min:0'],
            'attendance_youth' => ['nullable', 'integer', 'min:0'],
            'attendance_elderly' => ['nullable', 'integer', 'min:0'],
            'attendance_disability' => ['nullable', 'integer', 'min:0'],
            'attendance_vulnerable' => ['nullable', 'integer', 'min:0'],
            'attendance_breakdown' => ['nullable', 'array'],
            'vulnerable_groups_present' => ['boolean'],
            'vulnerable_groups' => ['nullable', 'array'],

            'status' => ['nullable', Rule::in(['draft', 'logged', 'verified'])],
            'custom_fields' => ['nullable', 'array'],
            'captured_at' => ['nullable', 'date'],

            'stakeholder_ids' => ['nullable', 'array'],
            'stakeholder_ids.*' => ['integer', 'exists:stakeholders,id'],

            'participants' => ['nullable', 'array'],
            'participants.*.name' => ['nullable', 'string', 'max:160'],
            'participants.*.stakeholder_id' => ['nullable', 'integer', 'exists:stakeholders,id'],
            'participants.*.category' => ['nullable', 'string', 'max:40'],
            'participants.*.organisation_name' => ['nullable', 'string', 'max:160'],
            'participants.*.position' => ['nullable', 'string', 'max:120'],
            'participants.*.phone' => ['nullable', 'string', 'max:40'],
            'participants.*.is_vulnerable' => ['nullable', 'boolean'],
            'participants.*.demographics' => ['nullable', 'array'],
            'participants.*.signed_attendance' => ['nullable', 'boolean'],

            // Concerns raised in the meeting, captured once.
            'concerns' => ['nullable', 'array'],
            'concerns.*.title' => ['nullable', 'string', 'max:255'],
            'concerns.*.description' => ['required_with:concerns', 'string', 'max:20000'],
            'concerns.*.stakeholder_id' => ['nullable', 'integer', 'exists:stakeholders,id'],
            'concerns.*.grievance_category_id' => ['nullable', 'integer', 'exists:grievance_categories,id'],
            'concerns.*.severity_hint' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'concerns.*.raised_by' => ['nullable', 'string', 'max:160'],

            // Commitments made in the meeting, captured once.
            'commitments' => ['nullable', 'array'],
            'commitments.*.commitment_text' => ['required_with:commitments', 'string', 'max:20000'],
            'commitments.*.owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'commitments.*.due_date' => ['nullable', 'date'],
            'commitments.*.risk_level' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'commitments.*.stakeholder_ids' => ['nullable', 'array'],
        ], [
            'topic.required' => 'What was this engagement about?',
            'held_at.required' => 'When did this engagement take place?',
            'concerns.*.description.required_with' => 'Write down what the concern was.',
            'commitments.*.commitment_text.required_with' => 'Write down what was promised.',
        ]);
    }

    private function buildQuery(Request $request)
    {
        $query = Engagement::query()->where('project_id', $this->project()->id);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        QueryFilters::apply($query, $request, [
            'method', 'project_phase', 'planned_vs_actual', 'status', 'facilitator_id',
            'engagement_plan_id',
            'location_id' => fn ($q, $v) => QueryFilters::location($q, $v),
            'stakeholder_id' => fn ($q, $v) => $q->whereHas('stakeholders', fn ($w) => $w->where('stakeholders.id', $v)),
            'search' => fn ($q, $v) => $q->search($v),
            'unplanned' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->whereNull('engagement_plan_id') : $q,
        ]);

        QueryFilters::dateRange($query, $request, 'held_at');

        return $query;
    }

    private function summary(Request $request): array
    {
        $request = QueryFilters::without($request, ['planned_vs_actual', 'status', 'unplanned']);
        $base = fn () => $this->buildQuery($request);

        return [
            'total' => $base()->count(),
            'on_plan' => $base()->where('planned_vs_actual', 'on_plan')->count(),
            'late' => $base()->where('planned_vs_actual', 'late')->count(),
            'unplanned' => $base()->where('planned_vs_actual', 'unplanned')->count(),
            'total_attendance' => (int) $base()->sum('attendance_total'),
        ];
    }
}
