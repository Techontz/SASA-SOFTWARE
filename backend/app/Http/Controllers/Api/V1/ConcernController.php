<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Engagement\ConcernService;
use App\Domain\Grievance\IntakeEngine;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConcernResource;
use App\Http\Resources\GrievanceResource;
use App\Models\Concern;
use App\Models\Grievance;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConcernController extends Controller
{
    public function __construct(
        private readonly ConcernService $concerns,
        private readonly IntakeEngine $intake,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Concern::class);

        $query = $this->buildQuery($request)
            ->with(['engagement:id,reference,topic,held_at', 'stakeholder:id,reference,name', 'category:id,name', 'grievance:id,reference,status', 'owner:id,name']);

        QueryFilters::sort($query, $request, ['raised_on', 'reference', 'status', 'created_at'], '-raised_on');

        return ConcernResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        )->additional(['meta' => ['summary' => $this->summary($request)]]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Concern::class);

        $concern = $this->concerns->create($this->project(), $this->validated($request));

        return ApiResponse::data(new ConcernResource($concern), [], 201);
    }

    public function show(Concern $concern)
    {
        $this->authorize('view', $concern);

        $concern->load(['engagement', 'stakeholder', 'category', 'grievance', 'commitments', 'location', 'owner:id,name', 'attachments']);

        return ApiResponse::data(new ConcernResource($concern));
    }

    public function update(Request $request, Concern $concern)
    {
        $this->authorize('update', $concern);

        $updated = $this->concerns->update($concern, $this->validated($request, updating: true));

        return ApiResponse::data(new ConcernResource($updated->load(['engagement', 'stakeholder', 'category'])));
    }

    /**
     * Turn a concern into a formal grievance WITHOUT retyping it. The officer
     * confirms or adjusts the pre-filled draft; the substance comes across
     * exactly as it was captured in the field.
     */
    public function escalationDraft(Concern $concern)
    {
        $this->authorize('escalate', $concern);

        return ApiResponse::data([
            'draft' => $this->concerns->grievanceDraftFrom($concern->load('stakeholder')),
            'note' => 'These details come from the concern as it was recorded. Check them and add anything the complainant has since told you.',
        ]);
    }

    public function escalate(Request $request, Concern $concern)
    {
        $this->authorize('escalate', $concern);

        $draft = $this->concerns->grievanceDraftFrom($concern->load('stakeholder'));

        $overrides = $request->validate([
            'channel' => ['nullable', Rule::in(Grievance::CHANNELS)],
            'confidentiality' => ['nullable', Rule::in(['normal', 'confidential', 'anonymous'])],
            'category_id' => ['nullable', 'integer', 'exists:grievance_categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:grievance_categories,id'],
            'severity' => ['nullable', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'desired_resolution' => ['nullable', 'string', 'max:20000'],
            'complainant_name' => ['nullable', 'string', 'max:160'],
            'complainant_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $grievance = DB::transaction(function () use ($concern, $draft, $overrides) {
            $grievance = $this->intake->intake($this->project(), array_merge($draft, array_filter($overrides, fn ($v) => $v !== null)));
            $this->concerns->markEscalated($concern, $grievance->id, $grievance->reference);

            return $grievance;
        });

        return ApiResponse::data(
            new GrievanceResource($grievance->load(['category', 'subcategory', 'location'])),
            ['message' => "Concern {$concern->reference} is now grievance {$grievance->reference}."],
            201
        );
    }

    public function archive(Concern $concern)
    {
        $this->authorize('update', $concern);

        $concern->archive();

        return ApiResponse::message("{$concern->reference} has been archived.");
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'engagement_id' => ['nullable', 'integer', 'exists:engagements,id'],
            'stakeholder_id' => ['nullable', 'integer', 'exists:stakeholders,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'grievance_category_id' => ['nullable', 'integer', 'exists:grievance_categories,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => [$required, 'string', 'max:50000'],
            'raised_by' => ['nullable', 'string', 'max:160'],
            'raised_on' => ['nullable', 'date'],
            'severity_hint' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'status' => ['nullable', Rule::in(['open', 'addressed', 'escalated', 'closed'])],
            'response' => ['nullable', 'string', 'max:20000'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'captured_at' => ['nullable', 'date'],
        ], [
            'description.required' => 'Write down what the concern was, in the words it was raised.',
        ]);
    }

    private function buildQuery(Request $request)
    {
        $query = Concern::query()->where('project_id', $this->project()->id);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        QueryFilters::apply($query, $request, [
            'status', 'severity_hint', 'engagement_id', 'stakeholder_id', 'owner_id',
            'grievance_category_id',
            'location_id' => fn ($q, $v) => QueryFilters::location($q, $v),
            'escalated' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)
                ? $q->whereNotNull('grievance_id')
                : $q->whereNull('grievance_id'),
            'search' => fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%$v%")
                ->orWhere('description', 'like', "%$v%")
                ->orWhere('reference', 'like', "%$v%")),
        ]);

        QueryFilters::dateRange($query, $request, 'raised_on');

        return $query;
    }

    private function summary(Request $request): array
    {
        $request = QueryFilters::without($request, ['status', 'escalated', 'severity_hint']);
        $base = fn () => $this->buildQuery($request);
        $total = $base()->count();
        $escalated = $base()->whereNotNull('grievance_id')->count();

        return [
            'total' => $total,
            'open' => $base()->where('status', 'open')->count(),
            'escalated' => $escalated,
            'conversion_rate' => $total > 0 ? round($escalated / $total * 100, 1) : null,
        ];
    }
}
