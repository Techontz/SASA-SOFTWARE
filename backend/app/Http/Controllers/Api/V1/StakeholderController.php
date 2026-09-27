<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Export\ExportService;
use App\Domain\Stakeholder\PriorityCalculator;
use App\Domain\Stakeholder\StakeholderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStakeholderRequest;
use App\Http\Requests\UpdateStakeholderRequest;
use App\Http\Resources\StakeholderResource;
use App\Models\Stakeholder;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StakeholderController extends Controller
{
    public function __construct(
        private readonly StakeholderService $stakeholders,
        private readonly PriorityCalculator $priority,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Stakeholder::class);

        $query = $this->buildQuery($request)
            ->withCount(['engagements', 'concerns', 'grievances', 'commitments'])
            ->with(['owner:id,name', 'primaryLocation:id,name,path,level']);

        QueryFilters::sort($query, $request, [
            'name', 'reference', 'priority', 'priority_score', 'type', 'status',
            'review_date', 'created_at', 'updated_at', 'last_engaged_on',
        ]);

        $paginator = $query->paginate(QueryFilters::perPage($request))->withQueryString();

        return StakeholderResource::collection($paginator)->additional([
            'meta' => ['summary' => $this->summary($request)],
        ]);
    }

    public function store(StoreStakeholderRequest $request)
    {
        $this->authorize('create', Stakeholder::class);

        $stakeholder = $this->stakeholders->create($this->project(), $request->validated());

        return ApiResponse::data(
            new StakeholderResource($stakeholder->load(['owner', 'primaryLocation', 'contacts', 'currentAssessment'])),
            [],
            201
        );
    }

    public function show(Stakeholder $stakeholder)
    {
        $this->authorize('view', $stakeholder);

        $stakeholder->load([
            'owner:id,name', 'primaryLocation', 'contacts', 'currentAssessment',
            'assessments.assessor:id,name', 'attachments.uploader:id,name',
        ])->loadCount(['engagements', 'concerns', 'grievances', 'commitments', 'attachments']);

        return ApiResponse::data(new StakeholderResource($stakeholder));
    }

    public function update(UpdateStakeholderRequest $request, Stakeholder $stakeholder)
    {
        $this->authorize('update', $stakeholder);

        $updated = $this->stakeholders->update($stakeholder, $request->validated());

        return ApiResponse::data(new StakeholderResource($updated->load(['owner', 'primaryLocation', 'contacts'])));
    }

    /** "Delete" means archive with an audit event. Nothing is ever removed. */
    public function archive(Request $request, Stakeholder $stakeholder)
    {
        $this->authorize('archive', $stakeholder);

        $stakeholder->archive($request->input('reason'));

        return ApiResponse::message(
            "{$stakeholder->reference} has been archived. It stays on historical records and in the audit trail."
        );
    }

    public function restore(Stakeholder $stakeholder)
    {
        $this->authorize('archive', $stakeholder);

        $stakeholder->restore();

        return ApiResponse::data(new StakeholderResource($stakeholder->fresh()));
    }

    public function overridePriority(Request $request, Stakeholder $stakeholder)
    {
        $this->authorize('overridePriority', $stakeholder);

        $data = $request->validate([
            'priority' => ['required', 'in:high,medium,low'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $updated = $this->stakeholders->overridePriority($stakeholder, $data['priority'], $data['reason']);

        return ApiResponse::data(new StakeholderResource($updated->load('currentAssessment')));
    }

    public function recalculatePriority(Stakeholder $stakeholder)
    {
        $this->authorize('update', $stakeholder);

        $this->stakeholders->assess($stakeholder);

        return ApiResponse::data(new StakeholderResource($stakeholder->fresh('currentAssessment')));
    }

    /** Surfaced as a warning, not a block: a village really can hold two Johns. */
    public function duplicates(Request $request)
    {
        $this->authorize('viewAny', Stakeholder::class);

        $data = $request->validate([
            'name' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'village' => ['nullable', 'string'],
            'exclude_id' => ['nullable', 'integer'],
        ]);

        $matches = $this->stakeholders->findPotentialDuplicates(
            $this->project(),
            $data,
            $data['exclude_id'] ?? null
        );

        return ApiResponse::data($matches, [
            'note' => $matches->isEmpty()
                ? 'No similar records found.'
                : 'These records look similar. Check before adding a new one.',
        ]);
    }

    public function merge(Request $request, Stakeholder $stakeholder)
    {
        $this->authorize('merge', $stakeholder);

        $data = $request->validate([
            'survivor_id' => ['required', 'integer', 'exists:stakeholders,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $survivor = Stakeholder::findOrFail($data['survivor_id']);
        $this->authorize('update', $survivor);

        $result = $this->stakeholders->merge($stakeholder, $survivor, $data['reason']);

        return ApiResponse::data(new StakeholderResource($result));
    }

    /** Timeline across every module — the point of a shared data spine. */
    public function timeline(Stakeholder $stakeholder)
    {
        $this->authorize('view', $stakeholder);

        $events = collect();

        foreach ($stakeholder->engagements()->with('plan:id,reference')->get() as $engagement) {
            $events->push([
                'type' => 'engagement',
                'id' => $engagement->id,
                'reference' => $engagement->reference,
                'title' => $engagement->topic,
                'subtitle' => ucfirst(str_replace('_', ' ', (string) $engagement->method)),
                'status' => $engagement->planned_vs_actual,
                'at' => $engagement->held_at?->toIso8601String(),
                'href' => "/engagements/{$engagement->id}",
            ]);
        }

        foreach ($stakeholder->concerns()->get() as $concern) {
            $events->push([
                'type' => 'concern',
                'id' => $concern->id,
                'reference' => $concern->reference,
                'title' => $concern->title,
                'subtitle' => $concern->grievance_id ? 'Escalated to a grievance' : null,
                'status' => $concern->status,
                'at' => $concern->raised_on?->toIso8601String(),
                'href' => "/concerns/{$concern->id}",
            ]);
        }

        foreach ($stakeholder->grievances()->get() as $grievance) {
            $events->push([
                'type' => 'grievance',
                'id' => $grievance->id,
                'reference' => $grievance->reference,
                'title' => $grievance->title,
                'subtitle' => $grievance->severity ? $grievance->severityLabel() : null,
                'status' => $grievance->status,
                'at' => $grievance->received_at?->toIso8601String(),
                'href' => "/grievances/{$grievance->id}",
            ]);
        }

        foreach ($stakeholder->commitments()->get() as $commitment) {
            $events->push([
                'type' => 'commitment',
                'id' => $commitment->id,
                'reference' => $commitment->reference,
                'title' => mb_substr($commitment->commitment_text, 0, 120),
                'subtitle' => $commitment->due_date ? 'Due '.$commitment->due_date->toFormattedDateString() : null,
                'status' => $commitment->status,
                'at' => ($commitment->source_date ?? $commitment->created_at)?->toIso8601String(),
                'href' => "/commitments/{$commitment->id}",
            ]);
        }

        return ApiResponse::data($events->sortByDesc('at')->values());
    }

    public function priorityModel()
    {
        $settings = $this->priority->settings(
            $this->project()->organisation_id,
            $this->project()->id
        );

        return ApiResponse::data([
            'settings' => $settings,
            'formula' => 'score = (influence x w1) + (interest x w2) + (power x w3) + (impact x w4)',
            'note' => 'High counts 3, Medium 2, Low 1. A weight of 0 removes that dimension from the score. '
                .'The source stakeholder matrix was internally inconsistent, so these numbers are configuration, '
                .'not code — set them to whatever the project has agreed.',
        ]);
    }

    public function export(Request $request, ExportService $exports)
    {
        $this->authorize('export', Stakeholder::class);

        $format = $request->input('format', 'xlsx');

        $result = $exports->export(
            project: $this->project(),
            user: $request->user(),
            entity: 'stakeholders',
            format: $format,
            query: $this->buildQuery($request)->with(['owner:id,name']),
            shape: [
                'columns' => ['ID', 'Name', 'Type', 'Location', 'Phone', 'Email', 'Influence', 'Interest', 'Power', 'Impact', 'Score', 'Calculated', 'Stored priority', 'Vulnerable', 'Consent', 'Owner', 'Status', 'Review due'],
                'mapper' => fn (Stakeholder $s) => [
                    $s->reference, $s->name, ucfirst(str_replace('_', ' ', (string) $s->type)), $s->displayLocation(),
                    $s->phone, $s->email, $s->influence, $s->interest, $s->power, $s->impact,
                    $s->priority_score, $s->calculated_priority,
                    $s->priority.($s->priority_overridden ? ' (overridden)' : ''),
                    $s->is_vulnerable ? 'Yes' : 'No', $s->consent_status,
                    $s->owner?->name, $s->status, $s->review_date?->toDateString(),
                ],
            ],
            filters: $request->except(['page', 'per_page', 'format']),
        );

        return Storage::disk(config('filesystems.default', 'local'))
            ->download($result['path'], $result['filename']);
    }

    private function buildQuery(Request $request)
    {
        $query = Stakeholder::query()->where('project_id', $this->project()->id);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        QueryFilters::apply($query, $request, [
            'type', 'status', 'priority', 'owner_id', 'preferred_language',
            'is_vulnerable' => fn ($q, $v) => $q->where('is_vulnerable', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
            'is_indigenous_or_minority' => fn ($q, $v) => $q->where('is_indigenous_or_minority', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
            'consent_status',
            'location_id' => fn ($q, $v) => QueryFilters::location($q, $v, 'primary_location_id'),
            'region', 'district', 'ward', 'village',
            'search' => fn ($q, $v) => $q->search($v),
            'review' => fn ($q, $v) => $v === 'due' ? $q->dueForReview() : $q,
            'priority_overridden' => fn ($q, $v) => $q->where('priority_overridden', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
        ]);

        QueryFilters::dateRange($query, $request, 'created_at', 'created_from', 'created_to');

        return $query;
    }

    private function summary(Request $request): array
    {
        // The summary shows the shape of the register the user is looking
        // at, without being collapsed by the very filters it breaks down.
        $request = QueryFilters::without($request, ['status', 'priority', 'is_vulnerable', 'review']);
        $base = fn () => $this->buildQuery($request);

        return [
            'total' => $base()->count(),
            'active' => $base()->where('status', 'active')->count(),
            'high_priority' => $base()->where('priority', 'high')->count(),
            'vulnerable' => $base()->where('is_vulnerable', true)->count(),
            'due_review' => $base()->dueForReview()->count(),
        ];
    }
}
