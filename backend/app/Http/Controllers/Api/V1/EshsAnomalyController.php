<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Eshs\EshsConfiguration;
use App\Domain\Eshs\EshsDashboard;
use App\Domain\Eshs\EshsService;
use App\Domain\Export\ExportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Eshs\EshsAnomalyRequest;
use App\Http\Requests\Eshs\EshsProgressRequest;
use App\Http\Requests\Eshs\EshsSettingsRequest;
use App\Http\Requests\Eshs\EshsVerificationRequest;
use App\Http\Resources\EshsAnomalyResource;
use App\Models\EshsAnomaly;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** ESHS contractor assurance. Thin: validate, authorise, one service, a resource. */
class EshsAnomalyController extends Controller
{
    public function __construct(
        private readonly EshsService $anomalies,
        private readonly EshsConfiguration $configuration,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', EshsAnomaly::class);

        $query = $this->buildQuery($request)->with(['location', 'raiser:id,name', 'closer:id,name']);

        QueryFilters::sort($query, $request, ['raised_on', 'target_date', 'reference', 'risk_rating', 'status', 'contractor', 'created_at'], '-raised_on');

        return EshsAnomalyResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        );
    }

    public function summary(Request $request, EshsDashboard $dashboard)
    {
        $this->authorize('viewAny', EshsAnomaly::class);

        $filters = QueryFilters::without($request, ['status', 'stage', 'overdue', 'page', 'per_page', 'sort']);

        return ApiResponse::data($dashboard->summary($this->project(), $this->buildQuery($filters)));
    }

    public function options()
    {
        $this->authorize('viewAny', EshsAnomaly::class);

        return ApiResponse::data($this->configuration->options($this->project()));
    }

    public function store(EshsAnomalyRequest $request)
    {
        $this->authorize('create', EshsAnomaly::class);

        $anomaly = $this->anomalies->raise($this->project(), $request->validated());

        return ApiResponse::data(new EshsAnomalyResource($anomaly->load(['location', 'raiser:id,name'])), [], 201);
    }

    public function show(EshsAnomaly $anomaly)
    {
        $this->authorize('view', $anomaly);

        $anomaly->load(['location', 'raiser:id,name', 'closer:id,name', 'attachments' => fn ($q) => $q->whereNull('archived_at')->with('uploader:id,name')]);

        return ApiResponse::data((new EshsAnomalyResource($anomaly))->withHistory($this->anomalies->history($anomaly)));
    }

    public function update(EshsAnomalyRequest $request, EshsAnomaly $anomaly)
    {
        $this->authorize('edit', $anomaly);

        return $this->respondWith($this->anomalies->update($anomaly, $request->validated()));
    }

    public function progress(EshsProgressRequest $request, EshsAnomaly $anomaly)
    {
        $this->authorize('respond', $anomaly);

        return $this->respondWith($this->anomalies->recordProgress($anomaly, $request->validated()));
    }

    public function submit(EshsVerificationRequest $request, EshsAnomaly $anomaly)
    {
        $this->authorize('respond', $anomaly);

        return $this->respondWith($this->anomalies->submitForVerification($anomaly, $request->validated('notes')));
    }

    public function close(EshsVerificationRequest $request, EshsAnomaly $anomaly)
    {
        $this->authorize('verify', $anomaly);

        return $this->respondWith($this->anomalies->close($anomaly, $request->validated('notes'), $request->validated('closed_on')));
    }

    public function returnToContractor(EshsVerificationRequest $request, EshsAnomaly $anomaly)
    {
        $this->authorize('verify', $anomaly);

        return $this->respondWith($this->anomalies->returnToContractor($anomaly, (string) $request->validated('reason')));
    }

    public function archive(EshsAnomaly $anomaly)
    {
        $this->authorize('archive', $anomaly);

        $this->anomalies->archive($anomaly);

        return ApiResponse::message("{$anomaly->reference} has been archived. It stays in the audit trail.");
    }

    public function export(Request $request, ExportService $exports)
    {
        $this->authorize('viewAny', EshsAnomaly::class);

        $project = $this->project();
        $config = $this->configuration;

        $result = $exports->export(
            project: $project,
            user: $request->user(),
            entity: 'eshs_anomalies',
            format: $request->input('format', 'xlsx'),
            query: $this->buildQuery($request)->with(['location', 'raiser:id,name', 'closer:id,name'])->orderBy('raised_on'),
            shape: [
                'columns' => [
                    'ID', 'Date raised', 'Area / location', 'Contractor', 'Subcontractor', 'ESHS category', 'Subcategory',
                    'Anomaly description', 'Anomaly type', 'Risk rating', 'Root cause heading', 'Root cause',
                    'Action by contractor', 'Action owner', 'Action owner email', 'Action owner phone', 'Target date',
                    'Status', 'Overdue', 'Date closed', 'Verified & closed by', 'Raised by',
                ],
                'mapper' => fn (EshsAnomaly $a) => [
                    $a->reference, $a->raised_on?->toDateString(), $a->location?->path ?? $a->location?->name,
                    $a->contractor, $a->subcontractor, $config->categoryLabel($project, $a->category), $a->subcategory,
                    $a->description, $a->anomaly_type, $config->riskRatings($project)[$a->risk_rating] ?? $a->risk_rating,
                    $a->root_cause_group, $a->root_cause, $a->contractor_action, $a->action_owner_name,
                    $a->action_owner_email, $a->action_owner_phone, $a->target_date?->toDateString(),
                    $a->isAwaitingVerification() ? 'In progress (awaiting verification)' : ($a->status === 'in_progress' ? 'In progress' : ucfirst($a->status)),
                    $a->isOverdue() ? 'Yes' : 'No', $a->closed_on?->toDateString(), $a->closer?->name, $a->raiser?->name,
                ],
            ],
            filters: $request->except(['page', 'per_page', 'format']),
        );

        return Storage::disk(config('filesystems.default', 'local'))->download($result['path'], $result['filename']);
    }

    public function settings()
    {
        $this->authorize('viewAny', EshsAnomaly::class);

        return ApiResponse::data($this->configuration->settings($this->project()));
    }

    public function updateSettings(EshsSettingsRequest $request)
    {
        abort_unless($this->context()->can('configuration.manage'), 403, 'You do not have permission to change configuration on this project.');

        return ApiResponse::data(
            $this->configuration->updateSettings($this->project(), $request->validated()),
            ['message' => 'Saved. This change is recorded in the audit trail.'],
        );
    }

    private function respondWith(EshsAnomaly $anomaly)
    {
        $anomaly->load(['location', 'raiser:id,name', 'closer:id,name', 'attachments' => fn ($q) => $q->whereNull('archived_at')->with('uploader:id,name')]);

        return ApiResponse::data((new EshsAnomalyResource($anomaly))->withHistory($this->anomalies->history($anomaly)));
    }

    private function buildQuery(Request $request): Builder
    {
        $query = $this->anomalies->query($this->project());

        if (! $request->boolean('include_archived')) {
            $query->whereNull('eshs_anomalies.archived_at');
        }

        QueryFilters::apply($query, $request, [
            'status', 'category', 'subcategory', 'contractor', 'subcontractor', 'risk_rating', 'anomaly_type', 'root_cause_group',
            'stage' => fn ($q, $v) => match ($v) {
                'awaiting_verification' => $q->awaitingVerification(),
                'in_progress' => $q->where('status', 'in_progress')->whereNull('evidence_submitted_at'),
                'not_closed' => $q->notClosed(),
                default => $q->where('status', $v),
            },
            'location_id' => fn ($q, $v) => QueryFilters::location($q, $v),
            'overdue' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->overdue() : $q,
            'search' => fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('description', 'like', "%$v%")
                ->orWhere('reference', 'like', "%$v%")
                ->orWhere('subcategory', 'like', "%$v%")
                ->orWhere('contractor', 'like', "%$v%")),
        ]);

        QueryFilters::dateRange($query, $request, 'raised_on', 'raised_from', 'raised_to');

        return $query;
    }
}
