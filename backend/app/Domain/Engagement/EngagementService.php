<?php

namespace App\Domain\Engagement;

use App\Domain\Audit\AuditLogger;
use App\Models\Engagement;
use App\Models\EngagementPlan;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * MODULE 2 write path.
 *
 * The planned-vs-actual loop: an engagement logged against PLAN-0001 closes it
 * and carries a variance flag the dashboards read directly, rather than
 * recomputing across the whole table every time someone opens a chart.
 */
final class EngagementService
{
    /** Grace either side of the planned window before "late" is claimed. */
    private const ON_PLAN_GRACE_DAYS = 2;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ConcernService $concerns,
        private readonly CommitmentService $commitments,
    ) {}

    public function createPlan(Project $project, array $data): EngagementPlan
    {
        return DB::transaction(function () use ($project, $data) {
            $plan = new EngagementPlan;
            $plan->organisation_id = $project->organisation_id;
            $plan->project_id = $project->id;
            $plan->fill($this->planAttributes($data));
            $plan->captured_at = $data['captured_at'] ?? now();
            $plan->synced_at = now();
            $plan->save();

            if (($data['recurrence'] ?? 'once') !== 'once' && ! empty($data['recurrence_until'])) {
                $this->expandRecurrence($plan);
            }

            return $plan->fresh(['stakeholder', 'owner', 'location']);
        });
    }

    /** Materialise a recurring plan into real, individually trackable rows. */
    private function expandRecurrence(EngagementPlan $plan): void
    {
        $interval = match ($plan->recurrence) {
            'weekly' => '1 week',
            'monthly' => '1 month',
            'quarterly' => '3 months',
            'semi_annual' => '6 months',
            'annual' => '1 year',
            default => null,
        };

        if (! $interval || ! $plan->target_date || ! $plan->recurrence_until) {
            return;
        }

        $cursor = CarbonImmutable::parse($plan->target_date);
        $until = CarbonImmutable::parse($plan->recurrence_until);
        $created = 0;

        while ($created < 60) {
            $cursor = $cursor->add($interval);

            if ($cursor->greaterThan($until)) {
                break;
            }

            $copy = $plan->replicate(['reference', 'client_uuid', 'status', 'status_reason']);
            $copy->reference = null;
            $copy->client_uuid = null;
            $copy->status = 'planned';
            $copy->target_date = $cursor;
            $copy->window_start = $plan->window_start ? $cursor : null;
            $copy->window_end = $plan->window_end ? $cursor->addDays(
                CarbonImmutable::parse($plan->window_start ?? $plan->target_date)
                    ->diffInDays(CarbonImmutable::parse($plan->window_end))
            ) : null;
            $copy->recurrence = 'once';
            $copy->recurrence_until = null;
            $copy->recurrence_parent_id = $plan->id;
            $copy->save();

            $created++;
        }
    }

    /**
     * Log what actually happened. Concerns and commitments captured in the
     * same submission are created here so nothing is retyped later.
     */
    public function logEngagement(Project $project, array $data): Engagement
    {
        return DB::transaction(function () use ($project, $data) {
            $engagement = new Engagement;
            $engagement->organisation_id = $project->organisation_id;
            $engagement->project_id = $project->id;
            $engagement->fill($this->engagementAttributes($data));
            $engagement->captured_at = $data['captured_at'] ?? now();
            $engagement->synced_at = now();
            $engagement->status = $data['status'] ?? 'logged';

            $plan = ! empty($data['engagement_plan_id'])
                ? EngagementPlan::find($data['engagement_plan_id'])
                : null;

            $engagement->engagement_plan_id = $plan?->id;
            $engagement->planned_vs_actual = $this->classifyAgainstPlan($engagement, $plan)['status'];
            $engagement->variance_days = $this->classifyAgainstPlan($engagement, $plan)['variance_days'];
            $engagement->save();

            if ($plan && $plan->status === 'planned') {
                $plan->forceFill(['status' => 'completed'])->save();

                $this->audit->record(
                    action: 'engagement_plan.completed',
                    entity: $plan,
                    after: ['engagement' => $engagement->reference, 'planned_vs_actual' => $engagement->planned_vs_actual],
                    summary: "Closed by {$engagement->reference} ({$engagement->planned_vs_actual})",
                );
            }

            $this->syncParticipants($engagement, $data);

            foreach ($data['concerns'] ?? [] as $concernData) {
                if (empty($concernData['title']) && empty($concernData['description'])) {
                    continue;
                }

                $this->concerns->create($project, array_merge($concernData, [
                    'engagement_id' => $engagement->id,
                    'raised_on' => $concernData['raised_on'] ?? $engagement->held_at->toDateString(),
                ]));
            }

            foreach ($data['commitments'] ?? [] as $commitmentData) {
                if (empty($commitmentData['commitment_text'])) {
                    continue;
                }

                $this->commitments->create($project, array_merge($commitmentData, [
                    'engagement_id' => $engagement->id,
                    'source_type' => 'engagement',
                    'source_date' => $engagement->held_at->toDateString(),
                ]));
            }

            return $engagement->fresh(['plan', 'stakeholders', 'participants', 'concerns', 'commitments']);
        });
    }

    public function updateEngagement(Engagement $engagement, array $data): Engagement
    {
        return DB::transaction(function () use ($engagement, $data) {
            $engagement->fill($this->engagementAttributes($data));

            if (array_key_exists('engagement_plan_id', $data)) {
                $plan = $data['engagement_plan_id'] ? EngagementPlan::find($data['engagement_plan_id']) : null;
                $engagement->engagement_plan_id = $plan?->id;
                $classification = $this->classifyAgainstPlan($engagement, $plan);
                $engagement->planned_vs_actual = $classification['status'];
                $engagement->variance_days = $classification['variance_days'];
            }

            $engagement->save();
            $this->syncParticipants($engagement, $data);

            return $engagement->fresh(['plan', 'stakeholders', 'participants']);
        });
    }

    /**
     * @return array{status:string,variance_days:?int}
     */
    public function classifyAgainstPlan(Engagement $engagement, ?EngagementPlan $plan): array
    {
        if (! $plan) {
            return ['status' => 'unplanned', 'variance_days' => null];
        }

        if (in_array($plan->status, ['cancelled', 'postponed', 'rescheduled'], true)) {
            return ['status' => $plan->status === 'cancelled' ? 'cancelled' : 'rescheduled', 'variance_days' => null];
        }

        [$start, $end] = $plan->effectiveWindow();

        if (! $start || ! $end) {
            return ['status' => 'on_plan', 'variance_days' => null];
        }

        $held = CarbonImmutable::parse($engagement->held_at)->startOfDay();
        $start = CarbonImmutable::parse($start)->startOfDay();
        $end = CarbonImmutable::parse($end)->startOfDay();

        if ($held->between($start->subDays(self::ON_PLAN_GRACE_DAYS), $end->addDays(self::ON_PLAN_GRACE_DAYS))) {
            return ['status' => 'on_plan', 'variance_days' => 0];
        }

        return $held->lessThan($start)
            ? ['status' => 'early', 'variance_days' => -(int) $held->diffInDays($start)]
            : ['status' => 'late', 'variance_days' => (int) $end->diffInDays($held)];
    }

    /**
     * Sweep planned engagements whose window has passed with nothing logged.
     * Called by the scheduler.
     */
    public function markMissedPlans(?int $projectId = null): int
    {
        $plans = EngagementPlan::query()
            ->acrossTenants()
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->missed()
            ->get();

        foreach ($plans as $plan) {
            $plan->forceFill(['status' => 'missed'])->save();

            $this->audit->record(
                action: 'engagement_plan.missed',
                entity: $plan,
                after: ['target_date' => $plan->target_date?->toDateString()],
                summary: 'Planned engagement passed with nothing logged against it',
            );
        }

        return $plans->count();
    }

    /**
     * Explicit whitelists rather than a reliance on mass-assignment guards:
     * the submitted payload also carries nested participants, concerns and
     * commitments, which are separate records — not columns on this table.
     */
    private function planAttributes(array $data): array
    {
        return collect($data)->only([
            'client_uuid', 'title', 'project_phase', 'stakeholder_id', 'stakeholder_group',
            'purpose', 'method', 'target_date', 'window_start', 'window_end',
            'location_id', 'location_text', 'vulnerable_group_accommodation', 'accommodation_notes',
            'fpic_required', 'fpic_notes', 'grievance_channel_available', 'owner_id',
            'responsible_team', 'priority', 'recurrence', 'recurrence_until', 'status',
            'status_reason', 'budget_amount', 'budget_currency', 'resources_required', 'custom_fields',
        ])->all();
    }

    private function engagementAttributes(array $data): array
    {
        return collect($data)->only([
            'client_uuid', 'engagement_plan_id', 'topic', 'project_phase', 'location_id',
            'location_text', 'latitude', 'longitude', 'held_at', 'ended_at', 'method',
            'venue', 'organised_by', 'facilitator_id', 'aim', 'discussion_points', 'outcomes',
            'attendance_total', 'attendance_female', 'attendance_male', 'attendance_youth',
            'attendance_elderly', 'attendance_disability', 'attendance_vulnerable',
            'attendance_breakdown', 'vulnerable_groups_present', 'vulnerable_groups',
            'status', 'custom_fields',
        ])->all();
    }

    private function syncParticipants(Engagement $engagement, array $data): void
    {
        if (array_key_exists('stakeholder_ids', $data)) {
            $engagement->stakeholders()->sync(
                collect($data['stakeholder_ids'] ?? [])->mapWithKeys(fn ($id) => [$id => ['attended' => true]])->all()
            );
        }

        if (! array_key_exists('participants', $data) || ! is_array($data['participants'])) {
            return;
        }

        $engagement->participants()->delete();

        foreach ($data['participants'] as $participant) {
            if (empty($participant['name']) && empty($participant['stakeholder_id'])) {
                continue;
            }

            $engagement->participants()->create(array_merge(
                collect($participant)->only([
                    'stakeholder_id', 'name', 'category', 'organisation_name', 'position',
                    'phone', 'is_vulnerable', 'demographics', 'signed_attendance',
                ])->all(),
                [
                    'organisation_id' => $engagement->organisation_id,
                    'project_id' => $engagement->project_id,
                ]
            ));
        }
    }
}
