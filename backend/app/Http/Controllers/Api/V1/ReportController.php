<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Dashboard\DashboardFilters;
use App\Domain\Reporting\ReportBuilder;
use App\Domain\Reporting\ReportService;
use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function templates()
    {
        return ApiResponse::data(collect(ReportBuilder::TEMPLATES)->map(fn ($label, $key) => [
            'key' => $key,
            'name' => $label,
            'formats' => config('sasa.reports.formats'),
        ])->values());
    }

    public function definitions(Request $request)
    {
        abort_unless($this->context()->can('report.generate'), 403, 'You do not have permission to run reports.');

        $definitions = ReportDefinition::query()
            ->where('organisation_id', $this->project()->organisation_id)
            ->where(fn ($q) => $q->where('project_id', $this->project()->id)->orWhereNull('project_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return ApiResponse::data($definitions);
    }

    public function storeDefinition(Request $request)
    {
        abort_unless($this->context()->can('report.manage_definitions'), 403, 'You do not have permission to manage report definitions.');

        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'template' => ['required', Rule::in(array_keys(ReportBuilder::TEMPLATES))],
            'filters' => ['nullable', 'array'],
            'default_period' => ['nullable', 'array'],
            'formats' => ['nullable', 'array'],
            'schedule' => ['nullable', Rule::in(['weekly', 'monthly', 'quarterly'])],
            'recipients' => ['nullable', 'array'],
        ]);

        $definition = ReportDefinition::updateOrCreate(
            [
                'organisation_id' => $this->project()->organisation_id,
                'project_id' => $this->project()->id,
                'key' => $data['key'],
            ],
            array_merge($data, ['created_by' => $request->user()->id, 'is_active' => true])
        );

        return ApiResponse::data($definition, [], 201);
    }

    public function index(Request $request)
    {
        abort_unless($this->context()->can('report.generate'), 403, 'You do not have permission to see reports.');

        $query = Report::query()
            ->where('project_id', $this->project()->id)
            ->with('generator:id,name');

        QueryFilters::apply($query, $request, ['template', 'format', 'status']);
        QueryFilters::sort($query, $request, ['created_at', 'name', 'template'], '-created_at');

        return ApiResponse::data(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        );
    }

    public function generate(Request $request)
    {
        abort_unless($this->context()->can('report.generate'), 403, 'You do not have permission to run reports.');

        $data = $request->validate([
            'template' => ['required', Rule::in(array_keys(ReportBuilder::TEMPLATES))],
            'format' => ['required', Rule::in(config('sasa.reports.formats'))],
            'name' => ['nullable', 'string', 'max:160'],
            'definition_id' => ['nullable', 'integer', 'exists:report_definitions,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'location_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'severity' => ['nullable', 'integer', 'between:1,5'],
            'channel' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer'],
        ]);

        $report = $this->reports->generate(
            project: $this->project(),
            template: $data['template'],
            format: $data['format'],
            filters: DashboardFilters::fromRequest($this->project()->id, $data),
            generatedBy: $request->user(),
            definition: isset($data['definition_id']) ? ReportDefinition::find($data['definition_id']) : null,
            name: $data['name'] ?? null,
        );

        return ApiResponse::data($report, [
            'download_url' => "/api/v1/reports/{$report->id}/download",
        ], 201);
    }

    public function show(Report $report)
    {
        abort_unless($this->context()->can('report.generate'), 403, 'You do not have permission to see reports.');
        abort_unless($report->project_id === $this->project()->id, 404);

        return ApiResponse::data($report->load('generator:id,name'));
    }

    public function download(Report $report)
    {
        abort_unless($this->context()->can('report.generate'), 403, 'You do not have permission to download reports.');
        abort_unless($report->project_id === $this->project()->id, 404);

        return $this->reports->download($report);
    }
}
