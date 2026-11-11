<?php

namespace App\Http\Controllers\Api\V1\Pap;

use App\Domain\Audit\AuditLogger;
use App\Domain\Export\ExportService;
use App\Domain\Pap\PapDashboard;
use App\Domain\Pap\PapGrievances;
use App\Domain\Pap\PapOptions;
use App\Domain\Pap\PapService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pap\PapRequest;
use App\Http\Resources\Pap\PapResource;
use App\Http\Resources\Pap\PapSensitivity;
use App\Models\Pap;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PapController extends Controller
{
    public function __construct(private readonly PapService $paps) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Pap::class);

        $query = $this->buildQuery($request)
            ->with(['location:id,path', 'stakeholder:id,reference'])
            ->withSum(['payments as amount_paid_total' => fn ($q) => $q->whereNull('archived_at')], 'amount_paid')
            ->withSum(['payments as outstanding_total' => fn ($q) => $q->whereNull('archived_at')], 'outstanding_amount');

        QueryFilters::sort($query, $request, ['reference', 'full_name', 'village', 'household_id', 'created_at'], 'reference');

        return PapResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        );
    }

    /** Every dropdown list for the register, resolved for this project. */
    public function options(PapOptions $options)
    {
        $this->authorize('viewAny', Pap::class);

        $all = $options->all($this->project());

        return ApiResponse::data([
            'currency' => $all['currency'] ?? 'TZS',
            'lists' => collect($all)
                ->filter(fn ($value, $key) => is_array($value) && ! array_is_list($value)
                    && ! in_array($key, ['stakeholder_vulnerability_map', 'stakeholder_defaults'], true))
                ->map(fn (array $list) => collect($list)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values())
                ->all(),
        ]);
    }

    public function summary(PapDashboard $dashboard)
    {
        $this->authorize('viewAny', Pap::class);

        return ApiResponse::data($dashboard->summary($this->project()));
    }

    public function store(PapRequest $request)
    {
        $this->authorize('create', Pap::class);

        $pap = $this->paps->create($this->project(), $request->validated());

        return ApiResponse::data(new PapResource($pap), [], 201);
    }

    public function show(Pap $pap, PapDashboard $dashboard, AuditLogger $audit)
    {
        $this->authorize('view', $pap);

        $pap->load(['location', 'stakeholder:id,reference,name', 'attachments.uploader:id,name']);

        // Reading a National ID is a sensitive view, and is logged as one.
        if ($pap->national_id && PapSensitivity::canSee()) {
            $audit->sensitiveView($pap, 'viewed_pap_identity');
        }

        return ApiResponse::data(new PapResource($pap), $dashboard->forPap($pap));
    }

    public function update(PapRequest $request, Pap $pap)
    {
        $this->authorize('update', $pap);

        $updated = $this->paps->update($pap, $request->validated());

        return ApiResponse::data(new PapResource($updated));
    }

    public function archive(Request $request, Pap $pap)
    {
        $this->authorize('archive', $pap);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $this->paps->archive($pap, $data['reason'] ?? null);

        return ApiResponse::message("{$pap->reference} has been archived, with its stakeholder record.");
    }

    /** Part K — the PAP's cases in the grievance module. */
    public function grievances(Pap $pap, PapGrievances $grievances)
    {
        $this->authorize('view', $pap);

        $result = $grievances->forPap($pap);

        return ApiResponse::data($result['data'], ['can_view' => $result['can_view']]);
    }

    public function export(Request $request, ExportService $exports)
    {
        $this->authorize('export', Pap::class);

        $sensitive = PapSensitivity::canSee();

        $columns = ['PAP ID', 'Household ID', 'Full name', 'Gender', 'Age', 'Marital status', 'Phone',
            'Region', 'District', 'Ward', 'Village', 'Kitongoji', 'Vulnerability', 'Stakeholder',
            'Amount paid', 'Outstanding', 'Status'];

        if ($sensitive) {
            array_splice($columns, 3, 0, ['National ID (NIDA)']);
        }

        $result = $exports->export(
            project: $this->project(),
            user: $request->user(),
            entity: 'paps',
            format: $request->input('format', 'xlsx'),
            query: $this->buildQuery($request)
                ->with(['stakeholder:id,reference'])
                ->withSum(['payments as amount_paid_total' => fn ($q) => $q->whereNull('archived_at')], 'amount_paid')
                ->withSum(['payments as outstanding_total' => fn ($q) => $q->whereNull('archived_at')], 'outstanding_amount'),
            shape: [
                'columns' => $columns,
                'mapper' => function (Pap $pap) use ($sensitive) {
                    $row = [
                        $pap->reference, $pap->household_id, $pap->full_name, $pap->gender, $pap->age(),
                        $pap->marital_status, $pap->phone, $pap->region, $pap->district, $pap->ward,
                        $pap->village, $pap->kitongoji, $pap->vulnerability_category, $pap->stakeholder?->reference,
                        (float) ($pap->amount_paid_total ?? 0), (float) ($pap->outstanding_total ?? 0), $pap->status,
                    ];

                    if ($sensitive) {
                        array_splice($row, 3, 0, [$pap->national_id]);
                    }

                    return $row;
                },
            ],
            filters: $request->except(['page', 'per_page', 'format']),
        );

        return Storage::disk(config('filesystems.default', 'local'))->download($result['path'], $result['filename']);
    }

    private function buildQuery(Request $request)
    {
        $query = Pap::query()->where('paps.project_id', $this->project()->id);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('paps.archived_at');
        }

        QueryFilters::apply($query, $request, [
            'gender', 'vulnerability_category', 'household_id', 'village', 'marital_status',
            'location_id' => fn ($q, $v) => QueryFilters::location($q, $v),
            'vulnerable' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->vulnerable() : $q,
            'relocated' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)
                ? $q->whereHas('relocations', fn ($r) => $r->whereNull('archived_at')->where('relocation_status', 'relocated'))
                : $q,
            'payment_status' => fn ($q, $v) => $q->whereHas('payments', fn ($p) => $p->whereNull('archived_at')->where('payment_status', $v)),
            'outstanding' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)
                ? $q->whereHas('payments', fn ($p) => $p->whereNull('archived_at')->where('outstanding_amount', '>', 0))
                : $q,
            'search' => fn ($q, $v) => $q->search((string) $v),
        ]);

        return $query;
    }
}
