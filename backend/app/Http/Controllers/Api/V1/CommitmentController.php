<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Engagement\CommitmentService;
use App\Domain\Export\ExportService;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommitmentResource;
use App\Models\Commitment;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CommitmentController extends Controller
{
    public function __construct(private readonly CommitmentService $commitments) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Commitment::class);

        $query = $this->buildQuery($request)
            ->with(['owner:id,name', 'stakeholders:id,reference,name', 'engagement:id,reference,topic,held_at', 'grievance:id,reference']);

        QueryFilters::sort($query, $request, ['due_date', 'reference', 'status', 'risk_level', 'created_at'], 'due_date');

        return CommitmentResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        )->additional(['meta' => ['summary' => $this->summary($request)]]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Commitment::class);

        $commitment = $this->commitments->create($this->project(), $this->validated($request));

        return ApiResponse::data(new CommitmentResource($commitment), [], 201);
    }

    public function show(Commitment $commitment)
    {
        $this->authorize('view', $commitment);

        $commitment->load(['owner:id,name', 'verifier:id,name', 'stakeholders', 'engagement', 'grievance', 'concern', 'location', 'attachments.uploader:id,name']);

        return ApiResponse::data(new CommitmentResource($commitment));
    }

    public function update(Request $request, Commitment $commitment)
    {
        $this->authorize('update', $commitment);

        $updated = $this->commitments->update($commitment, $this->validated($request, updating: true));

        return ApiResponse::data(new CommitmentResource($updated));
    }

    public function changeStatus(Request $request, Commitment $commitment)
    {
        $this->authorize('update', $commitment);

        $data = $request->validate([
            'status' => ['required', Rule::in(Commitment::STATUSES)],
            'completed_on' => ['nullable', 'date'],
            'evidence_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $updated = $this->commitments->changeStatus($commitment, $data['status'], $data);

        return ApiResponse::data(new CommitmentResource($updated));
    }

    /** Verification is a separate act, by a different person, on the record. */
    public function verify(Request $request, Commitment $commitment)
    {
        $this->authorize('verify', $commitment);

        $data = $request->validate([
            'verification_status' => ['required', Rule::in(['verified', 'disputed', 'unverified'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $updated = $this->commitments->verify($commitment, $data['verification_status'], $data['notes'] ?? null);

        return ApiResponse::data(new CommitmentResource($updated->load('verifier')));
    }

    public function archive(Commitment $commitment)
    {
        $this->authorize('update', $commitment);

        $commitment->archive();

        return ApiResponse::message("{$commitment->reference} has been archived.");
    }

    public function export(Request $request, ExportService $exports)
    {
        $this->authorize('viewAny', Commitment::class);

        $result = $exports->export(
            project: $this->project(),
            user: $request->user(),
            entity: 'commitments',
            format: $request->input('format', 'xlsx'),
            query: $this->buildQuery($request)->with(['owner:id,name', 'stakeholders:id,name', 'engagement:id,reference']),
            shape: [
                'columns' => ['ID', 'Commitment', 'Source', 'Made on', 'Stakeholders', 'Owner', 'Due', 'Risk', 'Status', 'Completed', 'Verification'],
                'mapper' => fn (Commitment $c) => [
                    $c->reference, $c->commitment_text, $c->engagement?->reference ?? ucfirst($c->source_type),
                    $c->source_date?->toDateString(), $c->stakeholders->pluck('name')->implode(', '),
                    $c->owner?->name, $c->due_date?->toDateString(), $c->risk_level,
                    $c->status, $c->completed_on?->toDateString(), $c->verification_status,
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
            'commitment_text' => [$required, 'string', 'max:20000'],
            'engagement_id' => ['nullable', 'integer', 'exists:engagements,id'],
            'concern_id' => ['nullable', 'integer', 'exists:concerns,id'],
            'grievance_id' => ['nullable', 'integer', 'exists:grievances,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'source_type' => ['nullable', Rule::in(['engagement', 'grievance', 'concern', 'manual'])],
            'source_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_team' => ['nullable', 'string', 'max:60'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'risk_level' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'status' => ['nullable', Rule::in(Commitment::STATUSES)],
            'evidence_notes' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'custom_fields' => ['nullable', 'array'],
            'stakeholder_ids' => ['nullable', 'array'],
            'stakeholder_ids.*' => ['integer', 'exists:stakeholders,id'],
            'captured_at' => ['nullable', 'date'],
        ], [
            'commitment_text.required' => 'Write down what was promised, in the words it was promised.',
        ]);
    }

    private function buildQuery(Request $request)
    {
        $query = Commitment::query()->where('project_id', $this->project()->id);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        QueryFilters::apply($query, $request, [
            'status', 'risk_level', 'priority', 'owner_id', 'verification_status',
            'source_type', 'engagement_id', 'grievance_id',
            'location_id' => fn ($q, $v) => QueryFilters::location($q, $v),
            'stakeholder_id' => fn ($q, $v) => $q->whereHas('stakeholders', fn ($w) => $w->where('stakeholders.id', $v)),
            'overdue' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->overdue() : $q,
            'upcoming' => fn ($q, $v) => $q->upcoming((int) $v),
            'unverified' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->unverified() : $q,
            'search' => fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('commitment_text', 'like', "%$v%")
                ->orWhere('reference', 'like', "%$v%")),
        ]);

        QueryFilters::dateRange($query, $request, 'due_date', 'due_from', 'due_to');

        return $query;
    }

    private function summary(Request $request): array
    {
        $request = QueryFilters::without($request, ['status', 'overdue', 'unverified', 'risk_level', 'verification_status']);
        $base = fn () => $this->buildQuery($request);

        return [
            'total' => $base()->count(),
            'open' => $base()->open()->count(),
            'overdue' => $base()->overdue()->count(),
            'fulfilled' => $base()->where('status', 'fulfilled')->count(),
            'high_risk' => $base()->open()->highRisk()->count(),
            'unverified' => $base()->unverified()->count(),
        ];
    }
}
