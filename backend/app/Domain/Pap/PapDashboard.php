<?php

namespace App\Domain\Pap;

use App\Domain\Grievance\GrievanceVisibility;
use App\Models\Grievance;
use App\Models\Pap;
use App\Models\Pap\PapLivelihood;
use App\Models\Pap\PapPayment;
use App\Models\Pap\PapRelocation;
use App\Models\Pap\PapValuation;
use App\Models\Project;

/**
 * The PAP register's headline figures. Every figure carries its definition —
 * a number two people can read two ways is not a KPI.
 *
 * All figures count active (not archived) PAPs and active child records only.
 */
final class PapDashboard
{
    public function __construct(
        private readonly GrievanceVisibility $visibility,
        private readonly PapOptions $options,
    ) {}

    /** @return array{currency:string,kpis:array<int,array<string,mixed>>} */
    public function summary(Project $project): array
    {
        $paps = fn () => Pap::query()->where('project_id', $project->id)->whereNull('archived_at');
        $activePapIds = fn () => $paps()->select('id');

        $total = $paps()->count();
        $households = (int) $paps()->whereNotNull('household_id')->distinct()->count('household_id')
            + $paps()->whereNull('household_id')->count();

        // Budget: each PAP's most recent APPROVED valuation. A revaluation
        // replaces the earlier figure rather than adding to it.
        $budget = PapValuation::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->whereIn('pap_id', $activePapIds())
            ->where('approval_status', 'approved')
            ->orderByDesc('valuation_date')->orderByDesc('id')
            ->get(['pap_id', 'total_compensation_value'])
            ->unique('pap_id')
            ->sum(fn ($valuation) => (float) $valuation->total_compensation_value);

        $payments = fn () => PapPayment::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->whereIn('pap_id', $activePapIds());

        $paid = (float) $payments()->sum('amount_paid');
        $outstanding = (float) $payments()->sum('outstanding_amount');

        $stakeholderIds = $paps()->whereNotNull('stakeholder_id')->pluck('stakeholder_id');
        $grievances = fn () => $this->visibility->scopeVisible(
            Grievance::query()
                ->where('project_id', $project->id)
                ->whereNull('archived_at')
                ->whereIn('stakeholder_id', $stakeholderIds)
        );

        $relocated = PapRelocation::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->whereIn('pap_id', $activePapIds())
            ->where('relocation_status', 'relocated')
            ->distinct()->count('pap_id');

        $livelihoods = fn () => PapLivelihood::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->whereIn('pap_id', $activePapIds());
        $enrolled = $livelihoods()->count();
        $completed = $livelihoods()->where('programme_status', 'completed')->count();

        $kpi = fn (string $key, string $label, string $definition, string $unit, float|int|null $value, array $context = [], ?string $drill = null) => [
            'key' => $key, 'label' => $label, 'definition' => $definition, 'unit' => $unit,
            'value' => $value, 'delta_percent' => null, 'context' => $context, 'drill' => $drill,
        ];

        return [
            'currency' => (string) ($this->options->all($project)['currency'] ?? 'TZS'),
            'kpis' => [
                $kpi('total_paps', 'Total PAPs', 'Project Affected Persons on the register, excluding archived records.', 'count', $total, [], '/paps'),
                $kpi('households', 'Households', 'Distinct household IDs among active PAPs. A PAP with no household ID counts as a household of one.', 'count', $households),
                $kpi('male_paps', 'Male PAPs', 'Active PAPs recorded as male.', 'count', $paps()->where('gender', 'male')->count(), [], '/paps?gender=male'),
                $kpi('female_paps', 'Female PAPs', 'Active PAPs recorded as female.', 'count', $paps()->where('gender', 'female')->count(), [], '/paps?gender=female'),
                $kpi('vulnerable_paps', 'Vulnerable PAPs', 'Active PAPs with any vulnerability category other than "None".', 'count', $paps()->vulnerable()->count(), [], '/paps?vulnerable=1'),
                $kpi('compensation_budget', 'Compensation budget', 'Sum of each active PAP\'s most recent approved valuation (total compensation value). Draft and unapproved valuations are excluded.', 'currency', round($budget, 2)),
                $kpi('compensation_paid', 'Compensation paid', 'Sum of the amount paid on every active payment record.', 'currency', round($paid, 2)),
                $kpi('outstanding_payments', 'Outstanding payments', 'Sum of approved minus paid on every active payment record — money approved for payment but not yet paid.', 'currency', round($outstanding, 2), [
                    'payments_not_fully_paid' => $payments()->where('outstanding_amount', '>', 0)->count(),
                ]),
                $kpi('grievances_total', 'Grievances', 'Grievances in the grievance module linked to an active PAP\'s stakeholder record. Restricted categories you cannot see are not counted.', 'count', $grievances()->count()),
                $kpi('grievances_open', 'Open grievances', 'PAP grievances in any open status, including reopened and awaiting the complainant.', 'count', $grievances()->whereIn('status', Grievance::OPEN_STATUSES)->count()),
                $kpi('grievances_closed', 'Closed grievances', 'PAP grievances that are closed, rejected or withdrawn.', 'count', $grievances()->whereIn('status', Grievance::CLOSED_STATUSES)->count()),
                $kpi('paps_relocated', 'PAPs relocated', 'Active PAPs with at least one relocation record in status "Relocated".', 'count', $relocated, [], '/paps?relocated=1'),
                $kpi('livelihood_progress', 'Livelihood restoration progress', 'Completed livelihood restoration enrolments as a share of all active enrolments.', 'percent', $enrolled > 0 ? round($completed / $enrolled * 100, 1) : null, [
                    'enrolled' => $enrolled,
                    'completed' => $completed,
                    'needs_follow_up' => $livelihoods()->where('programme_status', 'needs_follow_up')->count(),
                ]),
            ],
        ];
    }

    /**
     * The money and progress summary shown on one PAP's record, plus the
     * number of active records in each section (for the section tabs).
     *
     * @return array{totals:array<string,float|null>,counts:array<string,int>}
     */
    public function forPap(Pap $pap): array
    {
        $active = fn (string $relation) => $pap->{$relation}()->whereNull('archived_at');

        $latestApproved = $active('valuations')
            ->where('approval_status', 'approved')
            ->orderByDesc('valuation_date')->orderByDesc('id')
            ->value('total_compensation_value');

        $counts = [];
        foreach (PapSections::all() as $key => $definition) {
            $counts[$key] = $active($definition['relation'])->count();
        }

        return [
            'totals' => [
                'approved_valuation' => $latestApproved !== null ? (float) $latestApproved : null,
                'entitlements_value' => (float) $active('entitlements')->sum('value'),
                'crop_compensation' => (float) $active('crops')->sum('compensation_amount'),
                'affected_land_ha' => (float) $active('landParcels')->sum('affected_area_ha'),
                'amount_approved' => (float) $active('payments')->sum('amount_approved'),
                'amount_paid' => (float) $active('payments')->sum('amount_paid'),
                'outstanding' => (float) $active('payments')->sum('outstanding_amount'),
            ],
            'counts' => $counts,
        ];
    }
}
