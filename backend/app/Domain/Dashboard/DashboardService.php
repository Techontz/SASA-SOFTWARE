<?php

namespace App\Domain\Dashboard;

use App\Domain\Grievance\GrievanceVisibility;
use App\Models\AuditLog;
use App\Models\Commitment;
use App\Models\Engagement;
use App\Models\EngagementPlan;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\GrievanceEscalation;
use App\Models\Location;
use App\Models\SlaClock;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The five dashboards, computed from live operational data.
 *
 * Every KPI here carries the key of its definition, so the UI can show what
 * the number means and the drill-through can reproduce exactly the record set
 * behind it. Confidential and restricted cases are excluded for users outside
 * the handling group, which is why two people on the same dashboard may
 * legitimately see different totals.
 */
final class DashboardService
{
    public function __construct(private readonly GrievanceVisibility $visibility) {}

    // ------------------------------------------------------------- 1. executive

    public function executive(DashboardFilters $filters): array
    {
        $previous = $filters->previousPeriod();

        $received = $this->grievances($filters)->whereBetween('received_at', [$filters->from, $filters->to])->count();
        $receivedPrevious = $this->grievances($previous)->whereBetween('received_at', [$previous->from, $previous->to])->count();
        $closedInPeriod = $this->grievances($filters)->whereBetween('closed_at', [$filters->from, $filters->to])->count();
        $openNow = $this->grievances($filters)->open()->count();

        $receivedInPeriodIds = $this->grievances($filters)
            ->whereBetween('received_at', [$filters->from, $filters->to])
            ->pluck('id');

        $resolvedOfReceived = $receivedInPeriodIds->isEmpty() ? 0 : Grievance::query()
            ->whereIn('id', $receivedInPeriodIds)
            ->closed()
            ->count();

        $plans = $this->plans($filters)->whereBetween('target_date', [$filters->from, $filters->to]);
        $plansTotal = (clone $plans)->whereNotIn('status', ['cancelled'])->count();
        $plansCompleted = (clone $plans)->where('status', 'completed')->count();

        $engagementsHeld = $this->engagements($filters)->whereBetween('held_at', [$filters->from, $filters->to])->count();

        return [
            'period' => $filters->toArray(),
            'kpis' => [
                $this->kpi('total_stakeholders', $this->stakeholders($filters)->count(), drill: 'stakeholders'),
                $this->kpi('active_stakeholders', $this->stakeholders($filters)->where('status', 'active')->count(), drill: 'stakeholders?status=active'),
                $this->kpi('engagements_completed', $engagementsHeld, drill: 'engagements'),
                $this->kpi(
                    'engagement_completion_rate',
                    $plansTotal > 0 ? round($plansCompleted / $plansTotal * 100, 1) : null,
                    context: ['completed' => $plansCompleted, 'planned' => $plansTotal],
                    drill: 'engagements/plans',
                ),
                $this->kpi('grievances_received', $received, delta: $this->delta($received, $receivedPrevious), drill: 'grievances'),
                $this->kpi('grievances_open', $openNow, drill: 'grievances?status=open'),
                $this->kpi('grievances_closed', $closedInPeriod, drill: 'grievances?status=closed'),
                $this->kpi(
                    'resolution_rate',
                    $receivedInPeriodIds->count() > 0 ? round($resolvedOfReceived / $receivedInPeriodIds->count() * 100, 1) : null,
                    context: ['closed' => $resolvedOfReceived, 'received' => $receivedInPeriodIds->count()],
                ),
                $this->kpi('average_resolution_days', $this->averageResolutionDays($filters)),
                $this->kpi('sla_compliance', $this->slaCompliance($filters)),
                $this->kpi('open_high_severity', $this->grievances($filters)->open()->highSeverity()->count(), drill: 'grievances?severity_min=4&status=open'),
                $this->kpi('commitments_overdue', $this->commitments($filters)->overdue()->count(), drill: 'commitments?overdue=1'),
            ],
            'trend' => $this->grievanceTrend($filters),
            'by_category' => $this->grievancesByCategory($filters),
            'by_location' => $this->grievancesByLocation($filters),
            'critical_actions' => $this->criticalActions($filters),
            'recent_activity' => $this->recentActivity($filters),
        ];
    }

    // ------------------------------------------------------ 2. SLA & timeliness

    public function timeliness(DashboardFilters $filters): array
    {
        $clocks = fn () => SlaClock::query()
            ->where('project_id', $filters->projectId)
            ->where('subject_type', 'grievance');

        $finished = (clone $clocks())->whereIn('state', ['met', 'met_late'])
            ->whereBetween('completed_at', [$filters->from, $filters->to]);

        return [
            'period' => $filters->toArray(),
            'kpis' => [
                $this->kpi('average_acknowledgement_hours', $this->averageAcknowledgementHours($filters)),
                $this->kpi('average_resolution_days', $this->averageResolutionDays($filters)),
                $this->kpi('sla_compliance', $this->slaCompliance($filters)),
                $this->kpi('sla_breaches', (clone $clocks())->where('state', 'breached')->count(), drill: 'grievances?sla=breached'),
            ],
            'clock_states' => (clone $clocks())
                ->select('clock', 'state', DB::raw('count(*) as total'))
                ->groupBy('clock', 'state')
                ->get()
                ->groupBy('clock')
                ->map(fn ($rows) => $rows->pluck('total', 'state'))
                ->all(),
            'open_clocks' => (clone $clocks())->running()->count(),
            'paused_clocks' => (clone $clocks())->where('state', 'paused')->count(),
            'paused_detail' => (clone $clocks())->where('state', 'paused')
                ->select('subject_id', 'clock', 'pause_reason', 'paused_at')
                ->limit(50)->get(),
            'by_clock' => (clone $finished)
                ->select('clock', DB::raw("sum(state = 'met') as on_time"), DB::raw('count(*) as total'))
                ->groupBy('clock')
                ->get()
                ->map(fn ($row) => [
                    'clock' => $row->clock,
                    'on_time' => (int) $row->on_time,
                    'total' => (int) $row->total,
                    'percent' => $row->total > 0 ? round($row->on_time / $row->total * 100, 1) : null,
                ])->all(),
            'by_category' => $this->slaBreakdown($filters, 'category_id'),
            'by_severity' => $this->slaBreakdown($filters, 'severity'),
            'by_assignee' => $this->slaBreakdown($filters, 'assigned_to_id'),
            'trend' => $this->slaTrend($filters),
        ];
    }

    // ----------------------------------------------------- 3. disaggregation

    /**
     * Configurable cross-tabs with re-identification protection: any cell
     * below the configured minimum (default 5) is suppressed rather than
     * displayed.
     */
    public function disaggregation(DashboardFilters $filters, string $dimension, string $against = 'category'): array
    {
        $minimum = (int) config('sasa.disaggregation.minimum_cell_size', 5);

        $rows = $this->grievances($filters)
            ->whereBetween('received_at', [$filters->from, $filters->to])
            ->get(['id', 'demographics', 'category_id', 'severity', 'channel', 'status', 'location_id', 'received_at', 'closed_at']);

        $categories = GrievanceCategory::whereIn('id', $rows->pluck('category_id')->filter()->unique())
            ->pluck('name', 'id');

        $table = [];
        $suppressed = 0;

        foreach ($rows as $row) {
            $dimensionValue = data_get($row->demographics, $dimension) ?? 'Not recorded';

            $againstValue = match ($against) {
                'category' => $categories[$row->category_id] ?? 'Unclassified',
                'severity' => $row->severity ? 'Level '.$row->severity : 'Not assessed',
                'channel' => str_replace('_', ' ', ucfirst($row->channel)),
                'timeliness' => $row->closed_at ? 'Closed' : 'Open',
                default => 'All',
            };

            $table[$dimensionValue][$againstValue] = ($table[$dimensionValue][$againstValue] ?? 0) + 1;
        }

        foreach ($table as $dimensionValue => $columns) {
            foreach ($columns as $column => $count) {
                if ($count < $minimum) {
                    $table[$dimensionValue][$column] = null; // suppressed
                    $suppressed++;
                }
            }
        }

        return [
            'period' => $filters->toArray(),
            'dimension' => $dimension,
            'against' => $against,
            'minimum_cell_size' => $minimum,
            'suppressed_cells' => $suppressed,
            'suppression_note' => "Cells with fewer than {$minimum} records are hidden to prevent anyone being identified from the numbers.",
            'columns' => collect($table)->flatMap(fn ($columns) => array_keys($columns))->unique()->sort()->values()->all(),
            'rows' => collect($table)->map(fn ($columns, $key) => [
                'dimension_value' => $key,
                'cells' => $columns,
                'total' => collect($columns)->filter()->sum(),
            ])->values()->all(),
            'uptake_equity' => $this->uptakeEquity($filters),
        ];
    }

    // ------------------------------------------------ 4. severity & escalation

    public function severity(DashboardFilters $filters): array
    {
        $inPeriod = fn () => $this->grievances($filters)->whereBetween('received_at', [$filters->from, $filters->to]);

        $distribution = (clone $inPeriod())
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $levels = collect(config('sasa.grievances.severity_levels'))
            ->map(fn ($level, $key) => [
                'level' => (int) $key,
                'label' => $level['label'],
                'description' => $level['description'],
                'total' => (int) ($distribution[$key] ?? 0),
            ])->values()->all();

        return [
            'period' => $filters->toArray(),
            'kpis' => [
                $this->kpi('open_high_severity', $this->grievances($filters)->open()->highSeverity()->count()),
                $this->kpi('escalations', GrievanceEscalation::where('project_id', $filters->projectId)
                    ->whereBetween('escalated_at', [$filters->from, $filters->to])->count()),
                $this->kpi('sla_breaches', SlaClock::where('project_id', $filters->projectId)
                    ->where('subject_type', 'grievance')->where('state', 'breached')->count()),
            ],
            'distribution' => $levels,
            'escalation_reasons' => GrievanceEscalation::where('project_id', $filters->projectId)
                ->whereBetween('escalated_at', [$filters->from, $filters->to])
                ->select('trigger', DB::raw('count(*) as total'))
                ->groupBy('trigger')
                ->get()
                ->map(fn ($row) => ['trigger' => str_replace('_', ' ', $row->trigger), 'total' => (int) $row->total])
                ->all(),
            'open_critical' => $this->grievances($filters)->open()->highSeverity()
                ->with(['category', 'assignee'])
                ->orderByDesc('severity')->orderBy('received_at')
                ->limit(25)->get()
                ->map(fn (Grievance $g) => [
                    'id' => $g->id,
                    'reference' => $g->reference,
                    'title' => $g->title,
                    'severity' => $g->severity,
                    'severity_label' => $g->severityLabel(),
                    'status' => $g->status,
                    'category' => $g->category?->name,
                    'assignee' => $g->assignee?->name,
                    'days_open' => $g->daysOpen(),
                    'resolution_sla_state' => $g->resolution_sla_state,
                ])->all(),
            'time_to_escalation_days' => $this->averageTimeToEscalation($filters),
            'resolution_status_by_severity' => (clone $inPeriod())
                ->select('severity', DB::raw("sum(status in ('closed','rejected','withdrawn')) as closed"), DB::raw('count(*) as total'))
                ->groupBy('severity')
                ->get()
                ->map(fn ($row) => [
                    'severity' => (int) $row->severity,
                    'closed' => (int) $row->closed,
                    'open' => (int) $row->total - (int) $row->closed,
                ])->all(),
        ];
    }

    // -------------------------------------- 5. engagement planning & commitments

    public function engagementCommitments(DashboardFilters $filters): array
    {
        $plans = fn () => $this->plans($filters)->whereBetween('target_date', [$filters->from, $filters->to]);
        $engagements = fn () => $this->engagements($filters)->whereBetween('held_at', [$filters->from, $filters->to]);

        $plansTotal = (clone $plans())->whereNot('status', 'cancelled')->count();
        $plansCompleted = (clone $plans())->where('status', 'completed')->count();

        return [
            'period' => $filters->toArray(),
            'kpis' => [
                $this->kpi('engagements_planned', $plansTotal, drill: 'engagements/plans'),
                $this->kpi('engagements_completed', (clone $engagements())->count(), drill: 'engagements'),
                $this->kpi('engagement_completion_rate', $plansTotal > 0 ? round($plansCompleted / $plansTotal * 100, 1) : null),
                $this->kpi('engagements_missed', (clone $plans())->where('status', 'missed')->count(), drill: 'engagements/plans?status=missed'),
                $this->kpi('total_attendance', (int) (clone $engagements())->sum('attendance_total')),
                $this->kpi('commitments_open', $this->commitments($filters)->open()->count(), drill: 'commitments?status=open'),
                $this->kpi('commitments_overdue', $this->commitments($filters)->overdue()->count(), drill: 'commitments?overdue=1'),
                $this->kpi('commitments_high_risk', $this->commitments($filters)->open()->highRisk()->count(), drill: 'commitments?risk_level=high'),
                $this->kpi('commitments_unverified', $this->commitments($filters)->unverified()->count(), drill: 'commitments?unverified=1'),
            ],
            'planned_vs_actual' => (clone $engagements())
                ->select('planned_vs_actual', DB::raw('count(*) as total'))
                ->groupBy('planned_vs_actual')
                ->pluck('total', 'planned_vs_actual')
                ->all(),
            'plan_status' => (clone $plans())
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'commitment_status' => $this->commitments($filters)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'engagement_methods' => (clone $engagements())
                ->select('method', DB::raw('count(*) as total'), DB::raw('sum(attendance_total) as attendance'))
                ->groupBy('method')
                ->get()
                ->map(fn ($row) => [
                    'method' => $row->method ?: 'Not recorded',
                    'total' => (int) $row->total,
                    'attendance' => (int) $row->attendance,
                ])->all(),
            'overdue_commitments' => $this->commitments($filters)->overdue()
                ->with(['owner', 'stakeholders:id,name'])
                ->orderBy('due_date')
                ->limit(25)->get()
                ->map(fn (Commitment $c) => [
                    'id' => $c->id,
                    'reference' => $c->reference,
                    'commitment_text' => mb_substr($c->commitment_text, 0, 160),
                    'due_date' => $c->due_date?->toDateString(),
                    'days_overdue' => $c->due_date ? (int) $c->due_date->diffInDays(today()) : null,
                    'risk_level' => $c->risk_level,
                    'owner' => $c->owner?->name,
                    'stakeholders' => $c->stakeholders->pluck('name')->all(),
                ])->all(),
            'commitments_with_open_grievances' => $this->commitmentsAlongsideOpenGrievances($filters),
            'trend' => $this->engagementTrend($filters),
        ];
    }

    // -------------------------------------------------------------- components

    private function kpi(string $key, mixed $value, ?float $delta = null, array $context = [], ?string $drill = null): array
    {
        $definition = MetricDefinitions::get($key);

        return [
            'key' => $key,
            'label' => $definition['label'] ?? $key,
            'definition' => $definition['definition'] ?? '',
            'unit' => $definition['unit'] ?? 'count',
            'value' => $value,
            'delta_percent' => $delta,
            'context' => $context,
            'drill' => $drill,
        ];
    }

    private function delta(int|float|null $current, int|float|null $previous): ?float
    {
        if (! $previous) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function grievances(DashboardFilters $filters): Builder
    {
        $query = Grievance::query()
            ->where('project_id', $filters->projectId)
            ->whereNull('archived_at');

        $this->visibility->scopeVisible($query);

        return $filters->applyGrievanceFilters($query);
    }

    private function stakeholders(DashboardFilters $filters): Builder
    {
        $query = Stakeholder::query()
            ->where('project_id', $filters->projectId)
            ->whereNull('archived_at')
            ->where('status', '!=', 'merged');

        $filters->applyLocation($query, 'primary_location_id');

        return $query;
    }

    private function engagements(DashboardFilters $filters): Builder
    {
        $query = Engagement::query()
            ->where('project_id', $filters->projectId)
            ->whereNull('archived_at');

        $filters->applyLocation($query);

        return $query->when($filters->projectPhase, fn ($q) => $q->where('project_phase', $filters->projectPhase));
    }

    private function plans(DashboardFilters $filters): Builder
    {
        $query = EngagementPlan::query()
            ->where('project_id', $filters->projectId)
            ->whereNull('archived_at');

        $filters->applyLocation($query);

        return $query
            ->when($filters->projectPhase, fn ($q) => $q->where('project_phase', $filters->projectPhase))
            ->when($filters->ownerId, fn ($q) => $q->where('owner_id', $filters->ownerId));
    }

    private function commitments(DashboardFilters $filters): Builder
    {
        $query = Commitment::query()
            ->where('project_id', $filters->projectId)
            ->whereNull('archived_at');

        $filters->applyLocation($query);

        return $query->when($filters->ownerId, fn ($q) => $q->where('owner_id', $filters->ownerId));
    }

    private function averageResolutionDays(DashboardFilters $filters): ?float
    {
        $value = $this->grievances($filters)
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$filters->from, $filters->to])
            ->selectRaw('avg(timestampdiff(hour, received_at, resolved_at)) as hours')
            ->value('hours');

        return $value !== null ? round($value / 24, 1) : null;
    }

    private function averageAcknowledgementHours(DashboardFilters $filters): ?float
    {
        $value = $this->grievances($filters)
            ->whereNotNull('acknowledged_at')
            ->where('acknowledgement_possible', true)
            ->whereBetween('acknowledged_at', [$filters->from, $filters->to])
            ->selectRaw('avg(timestampdiff(minute, received_at, acknowledged_at)) as minutes')
            ->value('minutes');

        return $value !== null ? round($value / 60, 1) : null;
    }

    private function slaCompliance(DashboardFilters $filters): ?float
    {
        $row = SlaClock::query()
            ->where('project_id', $filters->projectId)
            ->where('subject_type', 'grievance')
            ->whereIn('state', ['met', 'met_late'])
            ->whereBetween('completed_at', [$filters->from, $filters->to])
            ->selectRaw("sum(state = 'met') as on_time, count(*) as total")
            ->first();

        return $row && $row->total > 0 ? round($row->on_time / $row->total * 100, 1) : null;
    }

    private function slaBreakdown(DashboardFilters $filters, string $column): array
    {
        $rows = $this->grievances($filters)
            ->whereBetween('received_at', [$filters->from, $filters->to])
            ->selectRaw("$column as bucket")
            ->selectRaw("sum(resolution_sla_state in ('met','running')) as on_time")
            ->selectRaw("sum(resolution_sla_state = 'breached') as breached")
            ->selectRaw('count(*) as total')
            ->groupBy('bucket')
            ->get();

        $labels = match ($column) {
            'category_id' => GrievanceCategory::whereIn('id', $rows->pluck('bucket')->filter())->pluck('name', 'id'),
            'assigned_to_id' => User::whereIn('id', $rows->pluck('bucket')->filter())->pluck('name', 'id'),
            default => null,
        };

        return $rows->map(fn ($row) => [
            'bucket' => $labels ? ($labels[$row->bucket] ?? 'Unassigned') : ($row->bucket ? 'Level '.$row->bucket : 'Not assessed'),
            'on_time' => (int) $row->on_time,
            'breached' => (int) $row->breached,
            'total' => (int) $row->total,
            'compliance_percent' => $row->total > 0 ? round($row->on_time / $row->total * 100, 1) : null,
        ])->all();
    }

    private function grievanceTrend(DashboardFilters $filters): array
    {
        $format = $filters->days() > 120 ? '%Y-%m' : '%x-W%v';

        $received = $this->grievances($filters)
            ->whereBetween('received_at', [$filters->from, $filters->to])
            ->selectRaw("date_format(received_at, '$format') as bucket, count(*) as total")
            ->groupBy('bucket')->orderBy('bucket')->pluck('total', 'bucket');

        $closed = $this->grievances($filters)
            ->whereBetween('closed_at', [$filters->from, $filters->to])
            ->selectRaw("date_format(closed_at, '$format') as bucket, count(*) as total")
            ->groupBy('bucket')->orderBy('bucket')->pluck('total', 'bucket');

        return collect($received->keys())->merge($closed->keys())->unique()->sort()->values()
            ->map(fn ($bucket) => [
                'bucket' => $bucket,
                'received' => (int) ($received[$bucket] ?? 0),
                'closed' => (int) ($closed[$bucket] ?? 0),
            ])->all();
    }

    private function slaTrend(DashboardFilters $filters): array
    {
        $format = $filters->days() > 120 ? '%Y-%m' : '%x-W%v';

        return SlaClock::query()
            ->where('project_id', $filters->projectId)
            ->where('subject_type', 'grievance')
            ->whereIn('state', ['met', 'met_late'])
            ->whereBetween('completed_at', [$filters->from, $filters->to])
            ->selectRaw("date_format(completed_at, '$format') as bucket")
            ->selectRaw("sum(state = 'met') as on_time, count(*) as total")
            ->groupBy('bucket')->orderBy('bucket')
            ->get()
            ->map(fn ($row) => [
                'bucket' => $row->bucket,
                'compliance_percent' => $row->total > 0 ? round($row->on_time / $row->total * 100, 1) : null,
                'total' => (int) $row->total,
            ])->all();
    }

    private function engagementTrend(DashboardFilters $filters): array
    {
        $format = $filters->days() > 120 ? '%Y-%m' : '%x-W%v';

        $held = $this->engagements($filters)
            ->whereBetween('held_at', [$filters->from, $filters->to])
            ->selectRaw("date_format(held_at, '$format') as bucket, count(*) as total, sum(attendance_total) as attendance")
            ->groupBy('bucket')->orderBy('bucket')->get();

        $planned = $this->plans($filters)
            ->whereBetween('target_date', [$filters->from, $filters->to])
            ->selectRaw("date_format(target_date, '$format') as bucket, count(*) as total")
            ->groupBy('bucket')->orderBy('bucket')->pluck('total', 'bucket');

        return $held->map(fn ($row) => [
            'bucket' => $row->bucket,
            'completed' => (int) $row->total,
            'planned' => (int) ($planned[$row->bucket] ?? 0),
            'attendance' => (int) $row->attendance,
        ])->all();
    }

    private function grievancesByCategory(DashboardFilters $filters): array
    {
        $rows = $this->grievances($filters)
            ->whereBetween('received_at', [$filters->from, $filters->to])
            ->selectRaw('category_id, count(*) as total')
            ->groupBy('category_id')->orderByDesc('total')->get();

        $names = GrievanceCategory::whereIn('id', $rows->pluck('category_id')->filter())->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'category_id' => $row->category_id,
            'category' => $names[$row->category_id] ?? 'Not yet classified',
            'total' => (int) $row->total,
        ])->all();
    }

    private function grievancesByLocation(DashboardFilters $filters): array
    {
        $rows = $this->grievances($filters)
            ->whereBetween('received_at', [$filters->from, $filters->to])
            ->selectRaw('location_id, count(*) as total')
            ->groupBy('location_id')->orderByDesc('total')->limit(12)->get();

        $names = Location::whereIn('id', $rows->pluck('location_id')->filter())->pluck('path', 'id');

        return $rows->map(fn ($row) => [
            'location_id' => $row->location_id,
            'location' => $names[$row->location_id] ?? 'Location not recorded',
            'total' => (int) $row->total,
        ])->all();
    }

    /** What needs a decision today, ordered by how much it matters. */
    private function criticalActions(DashboardFilters $filters): array
    {
        $actions = [];

        $breached = $this->grievances($filters)->open()->where('resolution_sla_state', 'breached')->count();
        if ($breached > 0) {
            $actions[] = [
                'kind' => 'sla_breach',
                'severity' => 'danger',
                'label' => $breached.' '.($breached === 1 ? 'case has' : 'cases have').' passed the resolution deadline',
                'href' => '/grievances?sla=breached&status=open',
                'count' => $breached,
            ];
        }

        $critical = $this->grievances($filters)->open()->where('severity', 5)->count();
        if ($critical > 0) {
            $actions[] = [
                'kind' => 'critical_case',
                'severity' => 'danger',
                'label' => $critical.' open Level 5 '.($critical === 1 ? 'case' : 'cases'),
                'href' => '/grievances?severity=5&status=open',
                'count' => $critical,
            ];
        }

        $unassigned = $this->grievances($filters)->open()->whereNull('assigned_to_id')->count();
        if ($unassigned > 0) {
            $actions[] = [
                'kind' => 'unassigned',
                'severity' => 'warning',
                'label' => $unassigned.' '.($unassigned === 1 ? 'case has' : 'cases have').' no owner',
                'href' => '/grievances?assignee=none&status=open',
                'count' => $unassigned,
            ];
        }

        $unacknowledged = $this->grievances($filters)->open()
            ->whereNull('acknowledged_at')
            ->where('acknowledgement_possible', true)
            ->where('acknowledgement_sla_state', '!=', 'met')
            ->count();
        if ($unacknowledged > 0) {
            $actions[] = [
                'kind' => 'unacknowledged',
                'severity' => 'warning',
                'label' => $unacknowledged.' '.($unacknowledged === 1 ? 'complainant is' : 'complainants are').' still waiting to be acknowledged',
                'href' => '/grievances?acknowledged=0&status=open',
                'count' => $unacknowledged,
            ];
        }

        $overdueCommitments = $this->commitments($filters)->overdue()->count();
        if ($overdueCommitments > 0) {
            $actions[] = [
                'kind' => 'overdue_commitment',
                'severity' => 'warning',
                'label' => $overdueCommitments.' overdue '.($overdueCommitments === 1 ? 'commitment' : 'commitments'),
                'href' => '/commitments?overdue=1',
                'count' => $overdueCommitments,
            ];
        }

        $missedPlans = $this->plans($filters)->where('status', 'missed')->count();
        if ($missedPlans > 0) {
            $actions[] = [
                'kind' => 'missed_engagement',
                'severity' => 'info',
                'label' => $missedPlans.' planned '.($missedPlans === 1 ? 'engagement was' : 'engagements were').' missed',
                'href' => '/engagements/plans?status=missed',
                'count' => $missedPlans,
            ];
        }

        $dueReview = $this->stakeholders($filters)->where('status', 'active')->dueForReview()->count();
        if ($dueReview > 0) {
            $actions[] = [
                'kind' => 'register_review',
                'severity' => 'info',
                'label' => $dueReview.' register '.($dueReview === 1 ? 'entry is' : 'entries are').' due for review',
                'href' => '/stakeholders?review=due',
                'count' => $dueReview,
            ];
        }

        return $actions;
    }

    private function recentActivity(DashboardFilters $filters): array
    {
        return AuditLog::query()
            ->where('project_id', $filters->projectId)
            ->where('is_sensitive_view', false)
            ->whereIn('action', [
                'grievance.created', 'grievance.resolved', 'grievance.closed', 'grievance.escalated',
                'grievance.reopened', 'engagement.created', 'commitment.created', 'commitment.verified',
                'stakeholder.created',
            ])
            ->orderByDesc('created_at')
            ->limit(12)
            ->get(['id', 'action', 'entity_type', 'entity_id', 'entity_reference', 'summary', 'user_name', 'created_at'])
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'reference' => $log->entity_reference,
                'summary' => $log->summary,
                'user' => $log->user_name,
                'at' => $log->created_at?->toIso8601String(),
            ])->all();
    }

    /**
     * The combination that reliably precedes a dispute: an overdue commitment
     * to a stakeholder who also has an open grievance.
     */
    private function commitmentsAlongsideOpenGrievances(DashboardFilters $filters): array
    {
        $stakeholderIds = $this->grievances($filters)->open()
            ->whereNotNull('stakeholder_id')->pluck('stakeholder_id')->unique();

        if ($stakeholderIds->isEmpty()) {
            return [];
        }

        return $this->commitments($filters)->overdue()
            ->whereHas('stakeholders', fn ($q) => $q->whereIn('stakeholders.id', $stakeholderIds))
            ->with('stakeholders:id,name,reference')
            ->limit(15)->get()
            ->map(fn (Commitment $c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'commitment_text' => mb_substr($c->commitment_text, 0, 160),
                'due_date' => $c->due_date?->toDateString(),
                'stakeholders' => $c->stakeholders->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'reference' => $s->reference])->all(),
            ])->all();
    }

    private function averageTimeToEscalation(DashboardFilters $filters): ?float
    {
        $value = GrievanceEscalation::query()
            ->join('grievances', 'grievances.id', '=', 'grievance_escalations.grievance_id')
            ->where('grievance_escalations.project_id', $filters->projectId)
            ->whereBetween('grievance_escalations.escalated_at', [$filters->from, $filters->to])
            ->selectRaw('avg(timestampdiff(hour, grievances.received_at, grievance_escalations.escalated_at)) as hours')
            ->value('hours');

        return $value !== null ? round($value / 24, 1) : null;
    }

    /**
     * Uptake equity: each vulnerable group's share of grievances and
     * engagements against its estimated share of the affected population.
     */
    private function uptakeEquity(DashboardFilters $filters): array
    {
        $registerTotal = $this->stakeholders($filters)->where('status', 'active')->count();

        if ($registerTotal === 0) {
            return [];
        }

        $vulnerableInRegister = $this->stakeholders($filters)
            ->where('status', 'active')->where('is_vulnerable', true)->count();

        $grievancesTotal = $this->grievances($filters)
            ->whereBetween('received_at', [$filters->from, $filters->to])->count();

        $grievancesFromVulnerable = $this->grievances($filters)
            ->whereBetween('received_at', [$filters->from, $filters->to])
            ->whereHas('stakeholder', fn ($q) => $q->where('is_vulnerable', true))
            ->count();

        $attendanceTotal = (int) $this->engagements($filters)
            ->whereBetween('held_at', [$filters->from, $filters->to])->sum('attendance_total');

        $attendanceVulnerable = (int) $this->engagements($filters)
            ->whereBetween('held_at', [$filters->from, $filters->to])->sum('attendance_vulnerable');

        $registerShare = round($vulnerableInRegister / $registerTotal * 100, 1);
        $grievanceShare = $grievancesTotal > 0 ? round($grievancesFromVulnerable / $grievancesTotal * 100, 1) : null;
        $engagementShare = $attendanceTotal > 0 ? round($attendanceVulnerable / $attendanceTotal * 100, 1) : null;

        return [
            [
                'group' => 'Vulnerable stakeholders',
                'population_share_percent' => $registerShare,
                'grievance_share_percent' => $grievanceShare,
                'engagement_share_percent' => $engagementShare,
                'grievance_gap' => $grievanceShare !== null ? round($grievanceShare - $registerShare, 1) : null,
                'engagement_gap' => $engagementShare !== null ? round($engagementShare - $registerShare, 1) : null,
                'note' => 'A negative gap means this group is raising fewer concerns, or attending fewer engagements, than its share of the register would suggest.',
            ],
        ];
    }
}
