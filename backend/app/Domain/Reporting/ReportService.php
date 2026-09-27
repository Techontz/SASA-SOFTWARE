<?php

namespace App\Domain\Reporting;

use App\Domain\Audit\AuditLogger;
use App\Domain\Dashboard\DashboardFilters;
use App\Domain\Reporting\Writers\CsvWriter;
use App\Domain\Reporting\Writers\ExcelWriter;
use App\Domain\Reporting\Writers\PdfWriter;
use App\Domain\Reporting\Writers\WordWriter;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates a report and RETAINS it with its parameters, generator and
 * timestamp — so a number in a past report can always be explained.
 */
final class ReportService
{
    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly AuditLogger $audit,
    ) {}

    public function generate(
        Project $project,
        string $template,
        string $format,
        DashboardFilters $filters,
        User $generatedBy,
        ?ReportDefinition $definition = null,
        ?string $name = null,
    ): Report {
        if (! array_key_exists($template, ReportBuilder::TEMPLATES)) {
            throw new DomainRuleException('That report template does not exist.', 'unknown_template');
        }

        if (! in_array($format, config('sasa.reports.formats'), true)) {
            throw new DomainRuleException('Reports can be produced as PDF, Word, Excel or CSV.', 'unknown_format');
        }

        $report = Report::create([
            'organisation_id' => $project->organisation_id,
            'project_id' => $project->id,
            'report_definition_id' => $definition?->id,
            'name' => $name ?? (ReportBuilder::TEMPLATES[$template].' — '.$filters->label()),
            'template' => $template,
            'format' => $format,
            'filters' => $filters->toArray(),
            'period_start' => $filters->from->toDateString(),
            'period_end' => $filters->to->toDateString(),
            'status' => 'generating',
            'generated_by' => $generatedBy->id,
        ]);

        try {
            $document = $this->builder->build($template, $project, $filters, $generatedBy);

            $relativePath = sprintf(
                'projects/%d/reports/%s-%s.%s',
                $project->id,
                Str::slug($template),
                Str::random(10),
                $format
            );

            $disk = Storage::disk(config('filesystems.default', 'local'));
            $disk->put($relativePath, '');
            $absolutePath = $disk->path($relativePath);

            match ($format) {
                'pdf' => (new PdfWriter)->write($document, $absolutePath),
                'docx' => (new WordWriter)->write($document, $absolutePath),
                'xlsx' => (new ExcelWriter)->write($document, $absolutePath),
                'csv' => (new CsvWriter)->write($document, $absolutePath),
            };

            $report->forceFill([
                'status' => 'ready',
                'path' => $relativePath,
                'size_bytes' => $disk->size($relativePath),
                'metrics' => $this->snapshot($document),
                'generated_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'report.generated',
                entity: $report,
                after: [
                    'template' => $template,
                    'format' => $format,
                    'period' => $filters->label(),
                    'filters' => $filters->toArray(),
                ],
                summary: "Generated {$report->name} as ".strtoupper($format),
            );
        } catch (\Throwable $e) {
            $report->forceFill([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ])->save();

            report($e);

            throw new DomainRuleException(
                'We could not build that report. The failure has been logged and nothing was changed.',
                'report_failed',
                500,
                ['report_id' => $report->id]
            );
        }

        return $report->fresh();
    }

    /** The numbers exactly as generated, so the file can be reconciled later. */
    private function snapshot(ReportDocument $document): array
    {
        $metrics = [];

        foreach ($document->sections as $section) {
            foreach ($section->kpis as $kpi) {
                if (isset($kpi['key'])) {
                    $metrics[$kpi['key']] = $kpi['value'];
                }
            }
        }

        $metrics['_row_counts'] = collect($document->sections)
            ->filter(fn ($section) => $section->type === ReportSection::TYPE_TABLE)
            ->mapWithKeys(fn ($section) => [$section->title => count($section->rows)])
            ->all();

        return $metrics;
    }

    public function download(Report $report)
    {
        if ($report->status !== 'ready' || ! $report->path) {
            throw new DomainRuleException('That report is not ready yet.', 'report_not_ready');
        }

        $this->audit->record(
            action: 'report.downloaded',
            entity: $report,
            summary: "Downloaded {$report->name}",
        );

        $extension = $report->format;
        $filename = Str::slug($report->name).'.'.$extension;

        return Storage::disk(config('filesystems.default', 'local'))->download($report->path, $filename);
    }

    /** Seeds the standard, immediately useful report definitions. */
    public static function systemDefinitions(): array
    {
        return [
            [
                'key' => 'executive_pack',
                'name' => 'Executive summary pack',
                'description' => 'The one-screen state of the project, in a form a lender or a mission visit can read.',
                'template' => 'executive_summary',
                'formats' => ['pdf', 'docx', 'xlsx'],
                'schedule' => 'monthly',
            ],
            [
                'key' => 'grievance_register',
                'name' => 'Grievance register',
                'description' => 'Every case received in the period, with classification, owner and timeliness.',
                'template' => 'grievance_register',
                'formats' => ['xlsx', 'csv', 'pdf'],
            ],
            [
                'key' => 'sla_performance',
                'name' => 'Grievance timeliness and SLA',
                'description' => 'Whether cases are handled inside the project\'s own business standard, and where they are not.',
                'template' => 'sla_performance',
                'formats' => ['pdf', 'xlsx', 'docx'],
                'schedule' => 'monthly',
            ],
            [
                'key' => 'stakeholder_register',
                'name' => 'Stakeholder register',
                'description' => 'The full register with priority scoring, consent basis and review dates.',
                'template' => 'stakeholder_register',
                'formats' => ['xlsx', 'csv'],
            ],
            [
                'key' => 'commitments_register',
                'name' => 'Commitments register',
                'description' => 'Every promise made, its owner, its due date and whether it was verified.',
                'template' => 'commitments_register',
                'formats' => ['xlsx', 'pdf', 'docx'],
                'schedule' => 'quarterly',
            ],
            [
                'key' => 'engagement_log',
                'name' => 'Engagement log and planned-vs-actual',
                'description' => 'What was planned, what happened, and the gap between them.',
                'template' => 'engagement_log',
                'formats' => ['xlsx', 'pdf'],
            ],
            [
                'key' => 'severity_escalation',
                'name' => 'Severity and escalation',
                'description' => 'The cases that could become incidents, and proof that escalation happened when it should have.',
                'template' => 'severity_escalation',
                'formats' => ['pdf', 'docx'],
            ],
        ];
    }
}
