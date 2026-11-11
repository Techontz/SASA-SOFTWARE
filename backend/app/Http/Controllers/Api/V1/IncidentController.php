<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Export\ExportService;
use App\Domain\Incident\IncidentOptions;
use App\Domain\Incident\IncidentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\IncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentService $incidents,
        private readonly IncidentOptions $options,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Incident::class);

        $query = $this->buildQuery($request)->with(['reporter:id,name', 'location:id,name,path']);

        QueryFilters::sort($query, $request, ['occurred_at', 'reference', 'status', 'potential_consequence', 'actual_consequence', 'created_at'], '-occurred_at');

        return IncidentResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        );
    }

    public function summary()
    {
        $this->authorize('viewAny', Incident::class);

        return ApiResponse::data($this->incidents->summary($this->project()));
    }

    /** The form's vocabulary. Anyone who can see or report incidents may read it. */
    public function options()
    {
        abort_unless(
            $this->context()->canAny('incident.view', 'incident.report', 'configuration.manage'),
            403,
            'You do not have access to incidents on this project.',
        );

        return ApiResponse::data($this->options->forProject($this->project()));
    }

    public function updateSettings(Request $request)
    {
        abort_unless($this->context()->can('configuration.manage'), 403, 'You do not have permission to change configuration on this project.');

        $data = $request->validate([
            'companies' => ['sometimes', 'array', 'max:200'],
            'companies.*' => ['string', 'max:160'],
            'contractor_managers' => ['sometimes', 'array', 'max:200'],
            'contractor_managers.*' => ['string', 'max:160'],
            'resident_engineers' => ['sometimes', 'array', 'max:200'],
            'resident_engineers.*' => ['string', 'max:160'],
        ]);

        return ApiResponse::data($this->options->updateLists($this->project(), $data), [
            'message' => 'Saved. This change is recorded in the audit trail.',
        ]);
    }

    public function store(IncidentRequest $request)
    {
        $this->authorize('create', Incident::class);

        $incident = $this->incidents->report($this->project(), $request->user(), $request->validated());

        return ApiResponse::data(new IncidentResource($incident), [], 201);
    }

    public function show(Incident $incident)
    {
        $this->authorize('view', $incident);

        $incident->load(['reporter:id,name', 'reviewer:id,name', 'closer:id,name', 'location', 'attachments.uploader:id,name']);

        return ApiResponse::data(new IncidentResource($incident));
    }

    public function update(IncidentRequest $request, Incident $incident)
    {
        $this->authorize('update', $incident);

        return ApiResponse::data(new IncidentResource($this->incidents->update($incident, $request->validated())));
    }

    public function changeStatus(Request $request, Incident $incident)
    {
        $this->authorize('manage', $incident);

        $data = $request->validate([
            'status' => ['required', Rule::in(['under_review', 'closed'])],
            'review_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $updated = $this->incidents->changeStatus($incident, $request->user(), $data['status'], $data['review_note'] ?? null);

        return ApiResponse::data(new IncidentResource($updated));
    }

    public function archive(Incident $incident)
    {
        $this->authorize('manage', $incident);

        $this->incidents->archive($incident);

        return ApiResponse::message("{$incident->reference} has been archived.");
    }

    public function export(Request $request, ExportService $exports)
    {
        $this->authorize('viewAny', Incident::class);

        $options = $this->options;

        $result = $exports->export(
            project: $this->project(),
            user: $request->user(),
            entity: 'incidents',
            format: $request->input('format', 'xlsx'),
            query: $this->buildQuery($request)->with(['reporter:id,name', 'location:id,name,path']),
            shape: [
                'columns' => [
                    'ID', 'Date', 'Company', 'Site / Location', 'Category', 'Incident type',
                    'Actual consequence', 'Potential consequence', 'Serious', 'Summary', 'Reported by',
                    'Contractor manager', 'Consultant\'s resident engineer', 'Status', 'Closed',
                ],
                'mapper' => fn (Incident $i) => [
                    $i->reference,
                    $i->occurred_at?->format('Y-m-d H:i'),
                    $i->company,
                    collect([$i->location?->path, $i->site_detail])->filter()->implode(' — '),
                    $options->categoryLabel($i->category),
                    $options->typeLabel($i->incident_type),
                    $options->levelLabel($i->actual_consequence),
                    $options->levelLabel($i->potential_consequence),
                    $i->isSerious() ? 'Yes' : 'No',
                    $i->summary,
                    $i->reporter?->name,
                    $i->contractor_manager,
                    $i->resident_engineer,
                    ucfirst(str_replace('_', ' ', (string) $i->status)),
                    $i->closed_at?->toDateString(),
                ],
            ],
            filters: $request->except(['page', 'per_page', 'format']),
        );

        return Storage::disk(config('filesystems.default', 'local'))->download($result['path'], $result['filename']);
    }

    private function buildQuery(Request $request)
    {
        $query = Incident::query()->where('project_id', $this->project()->id);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        QueryFilters::apply($query, $request, [
            'status', 'category', 'incident_type', 'company', 'actual_consequence', 'potential_consequence',
            'location_id' => fn ($q, $v) => QueryFilters::location($q, $v),
            'serious' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->serious() : $q,
            'search' => fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('summary', 'like', "%$v%")
                ->orWhere('reference', 'like', "%$v%")
                ->orWhere('site_detail', 'like', "%$v%")),
        ]);

        QueryFilters::dateRange($query, $request, 'occurred_at', 'from', 'to');

        return $query;
    }
}
