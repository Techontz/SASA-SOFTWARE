<?php

namespace App\Domain\Eshs;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard the client's "Digitalization Guidance" asks for: open
 * anomalies, overdue actions, closure rate, anomalies by category and
 * contractor performance. Every figure carries its definition, and every
 * figure is computed from the same (already contractor-scoped) query the list
 * uses, so a contractor sees only their own numbers.
 */
final class EshsDashboard
{
    /** One definition per figure — shown beside the number and nowhere else invented. */
    public const DEFINITIONS = [
        'total' => 'Every anomaly raised that matches the current filters, whatever its status. Archived anomalies are excluded.',
        'open' => 'Anomalies whose status is Open or In progress — not yet closed by the supervision team.',
        'overdue' => 'Anomalies that are not closed and whose target date is before today. Worked out on the day; never stored.',
        'awaiting_verification' => 'Anomalies where the contractor has submitted closure evidence and the supervision team has not yet closed or returned it.',
        'closure_rate' => 'Closed anomalies divided by all anomalies raised, as a percentage.',
        'avg_days_to_close' => 'Average number of calendar days from the date raised to the date closed, over closed anomalies.',
        'critical_open' => 'Anomalies rated Critical that are not yet closed.',
    ];

    public function __construct(private readonly EshsConfiguration $configuration) {}

    public function summary(Project $project, Builder $base): array
    {
        $q = fn () => (clone $base)->whereNull('eshs_anomalies.archived_at');

        $total = $q()->count();
        $closed = $q()->where('status', 'closed')->count();
        $open = $q()->notClosed()->count();
        $overdue = $q()->overdue()->count();
        $awaiting = $q()->awaitingVerification()->count();
        $criticalOpen = $q()->notClosed()->where('risk_rating', 'critical')->count();
        $highOpen = $q()->notClosed()->where('risk_rating', 'high')->count();
        $avgDays = $q()->where('status', 'closed')->whereNotNull('closed_on')
            ->avg(DB::raw('DATEDIFF(closed_on, raised_on)'));

        $kpis = [
            $this->kpi('open', 'Open anomalies', $open, 'count', 'eshs?stage=not_closed', ['high' => $highOpen, 'critical' => $criticalOpen]),
            $this->kpi('overdue', 'Overdue actions', $overdue, 'count', 'eshs?overdue=1'),
            $this->kpi('awaiting_verification', 'Awaiting verification', $awaiting, 'count', 'eshs?stage=awaiting_verification'),
            $this->kpi('closure_rate', 'Closure rate', $this->rate($closed, $total), 'percent', null, ['closed' => $closed, 'raised' => $total]),
            $this->kpi('avg_days_to_close', 'Average days to close', $avgDays === null ? null : round((float) $avgDays, 1), 'days', 'eshs?stage=closed'),
        ];

        return [
            'kpis' => $kpis,
            'totals' => [
                'total' => $total,
                'open' => $open,
                'in_progress' => $q()->where('status', 'in_progress')->count(),
                'awaiting_verification' => $awaiting,
                'overdue' => $overdue,
                'closed' => $closed,
                'critical_open' => $criticalOpen,
                'high_open' => $highOpen,
                'closure_rate' => $this->rate($closed, $total),
                'avg_days_to_close' => $avgDays === null ? null : round((float) $avgDays, 1),
            ],
            'by_category' => $this->byCategory($project, $q),
            'by_risk' => $this->byRisk($project, $q),
            'contractor_performance' => $this->contractorPerformance($q),
            'definitions' => self::DEFINITIONS,
        ];
    }

    private function byCategory(Project $project, callable $q): array
    {
        $rows = $q()
            ->select('category')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status <> 'closed' THEN 1 ELSE 0 END) as open_count")
            ->selectRaw("SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_count")
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        return collect($this->configuration->categories($project))
            ->keys()
            ->merge($rows->keys())
            ->unique()
            ->map(fn ($key) => [
                'category' => $key,
                'label' => $this->configuration->categoryLabel($project, $key),
                'total' => (int) ($rows[$key]->total ?? 0),
                'open' => (int) ($rows[$key]->open_count ?? 0),
                'closed' => (int) ($rows[$key]->closed_count ?? 0),
            ])
            ->values()
            ->all();
    }

    private function byRisk(Project $project, callable $q): array
    {
        $rows = $q()->select('risk_rating')->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status <> 'closed' THEN 1 ELSE 0 END) as open_count")
            ->groupBy('risk_rating')->get()->keyBy('risk_rating');

        return collect($this->configuration->riskRatings($project))
            ->map(fn ($label, $key) => [
                'risk_rating' => $key,
                'label' => $label,
                'total' => (int) ($rows[$key]->total ?? 0),
                'open' => (int) ($rows[$key]->open_count ?? 0),
            ])
            ->values()
            ->all();
    }

    private function contractorPerformance(callable $q): array
    {
        $today = today()->toDateString();

        return $q()
            ->select('contractor')
            ->selectRaw('COUNT(*) as raised')
            ->selectRaw("SUM(CASE WHEN status <> 'closed' THEN 1 ELSE 0 END) as open_count")
            ->selectRaw("SUM(CASE WHEN status <> 'closed' AND target_date IS NOT NULL AND target_date < ? THEN 1 ELSE 0 END) as overdue_count", [$today])
            ->selectRaw("SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_count")
            ->selectRaw("SUM(CASE WHEN status <> 'closed' AND risk_rating = 'critical' THEN 1 ELSE 0 END) as critical_open")
            ->selectRaw("AVG(CASE WHEN status = 'closed' AND closed_on IS NOT NULL THEN DATEDIFF(closed_on, raised_on) END) as avg_days")
            ->groupBy('contractor')
            ->orderByDesc('raised')
            ->orderBy('contractor')
            ->get()
            ->map(fn ($row) => [
                'contractor' => $row->contractor,
                'raised' => (int) $row->raised,
                'open' => (int) $row->open_count,
                'overdue' => (int) $row->overdue_count,
                'closed' => (int) $row->closed_count,
                'critical_open' => (int) $row->critical_open,
                'closure_rate' => $this->rate((int) $row->closed_count, (int) $row->raised),
                'avg_days_to_close' => $row->avg_days === null ? null : round((float) $row->avg_days, 1),
            ])
            ->all();
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole * 100, 1);
    }

    private function kpi(string $key, string $label, int|float|null $value, string $unit, ?string $drill, array $context = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'definition' => self::DEFINITIONS[$key],
            'unit' => $unit,
            'value' => $value,
            'delta_percent' => null,
            'context' => $context,
            'drill' => $drill,
        ];
    }
}
