<?php

namespace App\Domain\Reporting;

use App\Domain\Dashboard\DashboardFilters;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Dashboard\MetricDefinitions;
use App\Domain\Grievance\GrievanceVisibility;
use App\Models\Commitment;
use App\Models\Engagement;
use App\Models\Grievance;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\User;

/**
 * Builds a ReportDocument from LIVE records.
 *
 * There is no separate reporting database to reconcile: the same definition
 * run twice a week apart legitimately produces different numbers, and both are
 * traceable to the records that existed at run time.
 */
final class ReportBuilder
{
    public const TEMPLATES = [
        'executive_summary' => 'Executive summary',
        'grievance_register' => 'Grievance register',
        'sla_performance' => 'Grievance timeliness and SLA',
        'stakeholder_register' => 'Stakeholder register',
        'commitments_register' => 'Commitments register',
        'engagement_log' => 'Engagement log and planned-vs-actual',
        'severity_escalation' => 'Severity and escalation',
    ];

    public function __construct(
        private readonly DashboardService $dashboards,
        private readonly GrievanceVisibility $visibility,
    ) {}

    public function build(string $template, Project $project, DashboardFilters $filters, User $generatedBy): ReportDocument
    {
        $document = new ReportDocument(
            title: self::TEMPLATES[$template] ?? 'SASA report',
            subtitle: $project->name,
            projectName: $project->name,
            organisationName: $project->organisation?->name ?? '',
            periodLabel: $filters->label(),
            filters: $filters->toArray(),
            generatedBy: $generatedBy->name,
            generatedAt: now()->toDayDateTimeString(),
            logoPath: $project->logo_path ?? $project->organisation?->logo_path,
        );

        return match ($template) {
            'grievance_register' => $this->grievanceRegister($document, $filters),
            'sla_performance' => $this->slaPerformance($document, $filters),
            'stakeholder_register' => $this->stakeholderRegister($document, $filters),
            'commitments_register' => $this->commitmentsRegister($document, $filters),
            'engagement_log' => $this->engagementLog($document, $filters),
            'severity_escalation' => $this->severityEscalation($document, $filters),
            default => $this->executiveSummary($document, $filters),
        };
    }

    private function executiveSummary(ReportDocument $document, DashboardFilters $filters): ReportDocument
    {
        $data = $this->dashboards->executive($filters);

        $document->add(ReportSection::kpis(
            'Headline numbers',
            $data['kpis'],
            'Every figure is computed from the records that existed at the moment this report was generated.'
        ));

        $document->add(ReportSection::table(
            'Grievances by category',
            ['Category', 'Cases'],
            collect($data['by_category'])->map(fn ($row) => [$row['category'], $row['total']])->all(),
            MetricDefinitions::definition('grievances_received'),
        ));

        $document->add(ReportSection::table(
            'Grievances by location',
            ['Location', 'Cases'],
            collect($data['by_location'])->map(fn ($row) => [$row['location'], $row['total']])->all(),
        ));

        $document->add(ReportSection::table(
            'Received and closed over time',
            ['Period', 'Received', 'Closed'],
            collect($data['trend'])->map(fn ($row) => [$row['bucket'], $row['received'], $row['closed']])->all(),
        ));

        if ($data['critical_actions'] !== []) {
            $document->add(ReportSection::table(
                'What needs attention',
                ['Item', 'Count'],
                collect($data['critical_actions'])->map(fn ($row) => [$row['label'], $row['count']])->all(),
            ));
        }

        return $document;
    }

    private function grievanceRegister(ReportDocument $document, DashboardFilters $filters): ReportDocument
    {
        $stripIdentity = $this->visibility->exportStripsIdentity();

        $query = Grievance::query()
            ->where('project_id', $filters->projectId)
            ->whereNull('archived_at')
            ->whereBetween('received_at', [$filters->from, $filters->to])
            ->with(['category', 'subcategory', 'assignee', 'location']);

        $this->visibility->scopeVisible($query);
        $filters->applyGrievanceFilters($query);

        $columns = ['Case ID', 'Received', 'Channel', 'Category', 'Subcategory', 'Severity', 'Location', 'Status', 'Owner', 'Acknowledged', 'Resolved', 'Days open'];

        if (! $stripIdentity) {
            $columns[] = 'Complainant';
        }

        $rows = $query->orderBy('received_at')->get()->map(function (Grievance $g) use ($stripIdentity) {
            $row = [
                $g->reference,
                $g->received_at?->toDateString(),
                str_replace('_', ' ', $g->channel),
                $g->category?->name ?? 'Not classified',
                $g->subcategory?->name ?? '—',
                $g->severity ? 'Level '.$g->severity : 'Not assessed',
                $g->location?->path ?? $g->location_text ?? '—',
                str_replace('_', ' ', $g->status),
                $g->assignee?->name ?? 'Unassigned',
                $g->acknowledged_at?->toDateString() ?? ($g->acknowledgement_possible ? 'Not yet' : 'Not possible'),
                $g->resolved_at?->toDateString() ?? '—',
                $g->daysOpen(),
            ];

            if (! $stripIdentity) {
                $row[] = $g->isAnonymous() ? 'Anonymous' : ($g->complainant_name ?? '—');
            }

            return $row;
        })->all();

        $document->add(ReportSection::table('Grievance register', $columns, $rows,
            $stripIdentity
                ? 'Complainant identity has been removed from this export because the person generating it is not in the handling group.'
                : 'Contains complainant identity. Handle according to the project\'s confidentiality policy.'
        ));

        return $document;
    }

    private function slaPerformance(ReportDocument $document, DashboardFilters $filters): ReportDocument
    {
        $data = $this->dashboards->timeliness($filters);

        $document->add(ReportSection::kpis('Timeliness', $data['kpis']));

        $document->add(ReportSection::table(
            'On-time performance by clock',
            ['Clock', 'Met on time', 'Total finished', 'Compliance %'],
            collect($data['by_clock'])->map(fn ($row) => [
                ucfirst($row['clock']), $row['on_time'], $row['total'], $row['percent'] ?? '—',
            ])->all(),
        ));

        $document->add(ReportSection::table(
            'By category',
            ['Category', 'On time', 'Breached', 'Total', 'Compliance %'],
            collect($data['by_category'])->map(fn ($row) => [
                $row['bucket'], $row['on_time'], $row['breached'], $row['total'], $row['compliance_percent'] ?? '—',
            ])->all(),
        ));

        $document->add(ReportSection::table(
            'By severity',
            ['Severity', 'On time', 'Breached', 'Total', 'Compliance %'],
            collect($data['by_severity'])->map(fn ($row) => [
                $row['bucket'], $row['on_time'], $row['breached'], $row['total'], $row['compliance_percent'] ?? '—',
            ])->all(),
        ));

        $document->add(ReportSection::table(
            'Paused clocks',
            ['Case', 'Clock', 'Reason', 'Paused since'],
            collect($data['paused_detail'])->map(fn ($row) => [
                $row->subject_id, $row->clock, $row->pause_reason ?? '—', $row->paused_at,
            ])->all(),
            'Pauses are reported so the mechanism cannot be used to make breaches disappear.'
        ));

        return $document;
    }

    private function stakeholderRegister(ReportDocument $document, DashboardFilters $filters): ReportDocument
    {
        $rows = Stakeholder::query()
            ->where('project_id', $filters->projectId)
            ->whereNull('archived_at')
            ->with('owner')
            ->orderBy('reference')
            ->get()
            ->map(fn (Stakeholder $s) => [
                $s->reference,
                $s->name,
                ucfirst(str_replace('_', ' ', $s->type)),
                $s->displayLocation(),
                $s->phone ?? '—',
                ucfirst((string) $s->influence),
                ucfirst((string) $s->interest),
                ucfirst((string) $s->power),
                ucfirst((string) $s->impact),
                $s->priority_score,
                ucfirst((string) $s->calculated_priority),
                ucfirst((string) $s->priority).($s->priority_overridden ? ' (overridden)' : ''),
                $s->communication_frequency ?? '—',
                $s->is_vulnerable ? 'Yes' : 'No',
                ucfirst(str_replace('_', ' ', (string) $s->consent_status)),
                $s->owner?->name ?? '—',
                $s->review_date?->toDateString() ?? '—',
            ])->all();

        $document->add(ReportSection::table(
            'Stakeholder register',
            ['ID', 'Name', 'Type', 'Location', 'Phone', 'Influence', 'Interest', 'Power', 'Impact', 'Score', 'Calculated priority', 'Stored priority', 'Frequency', 'Vulnerable', 'Consent', 'Owner', 'Review due'],
            $rows,
            'The calculated priority is shown next to the stored priority so any override is visible.'
        ));

        return $document;
    }

    private function commitmentsRegister(ReportDocument $document, DashboardFilters $filters): ReportDocument
    {
        $rows = Commitment::query()
            ->where('project_id', $filters->projectId)
            ->whereNull('archived_at')
            ->with(['owner', 'stakeholders:id,name', 'engagement:id,reference,held_at'])
            ->orderBy('due_date')
            ->get()
            ->map(fn (Commitment $c) => [
                $c->reference,
                mb_substr($c->commitment_text, 0, 300),
                $c->engagement?->reference ?? '—',
                $c->source_date?->toDateString() ?? '—',
                $c->stakeholders->pluck('name')->implode(', ') ?: '—',
                $c->owner?->name ?? 'Unassigned',
                $c->due_date?->toDateString() ?? '—',
                ucfirst($c->risk_level),
                ucfirst(str_replace('_', ' ', $c->status)),
                $c->completed_on?->toDateString() ?? '—',
                ucfirst($c->verification_status),
            ])->all();

        $document->add(ReportSection::table(
            'Commitments register',
            ['ID', 'Commitment', 'Source engagement', 'Made on', 'Stakeholders', 'Owner', 'Due', 'Risk', 'Status', 'Completed', 'Verification'],
            $rows,
        ));

        return $document;
    }

    private function engagementLog(ReportDocument $document, DashboardFilters $filters): ReportDocument
    {
        $data = $this->dashboards->engagementCommitments($filters);

        $document->add(ReportSection::kpis('Engagement and commitments', $data['kpis']));

        $rows = Engagement::query()
            ->where('project_id', $filters->projectId)
            ->whereNull('archived_at')
            ->whereBetween('held_at', [$filters->from, $filters->to])
            ->with(['plan:id,reference,target_date', 'location'])
            ->orderBy('held_at')
            ->get()
            ->map(fn (Engagement $e) => [
                $e->reference,
                $e->held_at?->toDateString(),
                $e->topic,
                $e->method ?? '—',
                $e->location?->path ?? $e->location_text ?? '—',
                $e->plan?->reference ?? 'Unplanned',
                $e->plan?->target_date?->toDateString() ?? '—',
                str_replace('_', ' ', $e->planned_vs_actual),
                $e->variance_days ?? '—',
                $e->attendance_total,
                $e->attendance_female,
                $e->attendance_vulnerable,
            ])->all();

        $document->add(ReportSection::table(
            'Engagements held',
            ['ID', 'Date', 'Topic', 'Method', 'Location', 'Plan', 'Planned for', 'Planned vs actual', 'Variance (days)', 'Attendance', 'of which female', 'of which vulnerable'],
            $rows,
        ));

        return $document;
    }

    private function severityEscalation(ReportDocument $document, DashboardFilters $filters): ReportDocument
    {
        $data = $this->dashboards->severity($filters);

        $document->add(ReportSection::kpis('Severity and escalation', $data['kpis']));

        $document->add(ReportSection::table(
            'Severity distribution',
            ['Level', 'Meaning', 'Cases'],
            collect($data['distribution'])->map(fn ($row) => [$row['label'], $row['description'], $row['total']])->all(),
        ));

        $document->add(ReportSection::table(
            'Why cases were escalated',
            ['Trigger', 'Times'],
            collect($data['escalation_reasons'])->map(fn ($row) => [ucfirst($row['trigger']), $row['total']])->all(),
        ));

        $document->add(ReportSection::table(
            'Open critical cases',
            ['Case', 'Title', 'Severity', 'Status', 'Owner', 'Days open'],
            collect($data['open_critical'])->map(fn ($row) => [
                $row['reference'], $row['title'], $row['severity_label'], str_replace('_', ' ', $row['status']),
                $row['assignee'] ?? 'Unassigned', $row['days_open'],
            ])->all(),
        ));

        return $document;
    }
}
