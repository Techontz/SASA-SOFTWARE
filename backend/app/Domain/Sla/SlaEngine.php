<?php

namespace App\Domain\Sla;

use App\Domain\Audit\AuditLogger;
use App\Models\Grievance;
use App\Models\Project;
use App\Models\SlaClock;
use App\Models\SlaPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The configurable SLA engine.
 *
 * Nothing here hard-codes "7 working days". A policy is resolved
 * most-specific-first (project+category+severity, then category, then
 * severity, then the project base, then the organisation default); the clock
 * is then computed against the project's working calendar and holiday list.
 *
 * Pauses are recorded WITH a reason and are reported, because otherwise the
 * pause mechanism becomes a way to make breaches disappear.
 */
final class SlaEngine
{
    public function __construct(
        private readonly WorkingCalendarService $calendars,
        private readonly AuditLogger $audit,
    ) {}

    public function resolvePolicy(Project $project, string $clock, ?int $categoryId, ?int $severity): ?SlaPolicy
    {
        return SlaPolicy::query()
            ->where('organisation_id', $project->organisation_id)
            ->where('clock', $clock)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('project_id', $project->id)->orWhereNull('project_id'))
            ->where(fn ($q) => $q->where('category_id', $categoryId)->orWhereNull('category_id'))
            ->where(fn ($q) => $q->where('severity', $severity)->orWhereNull('severity'))
            ->where(fn ($q) => $q->where('country', $project->country)->orWhereNull('country'))
            ->orderByDesc('specificity')
            ->orderByRaw('project_id IS NULL')
            ->first();
    }

    /**
     * Start (or restart, for a new resolution cycle) a clock on a subject.
     */
    public function start(Model $subject, string $clock, ?CarbonImmutable $from = null, ?int $cycle = null): ?SlaClock
    {
        $project = $subject->project ?? Project::find($subject->project_id);

        if (! $project) {
            return null;
        }

        $categoryId = $subject instanceof Grievance ? $subject->category_id : null;
        $severity = $subject instanceof Grievance ? $subject->severity : null;
        $policy = $this->resolvePolicy($project, $clock, $categoryId, $severity);

        if (! $policy) {
            return null;
        }

        $calendar = $policy->calendar ?? $this->calendars->calendarFor($project);
        $start = $from ?? CarbonImmutable::now();
        $due = $this->dueAt($start, $policy, $calendar);
        $cycle ??= ($subject instanceof Grievance ? $subject->resolution_cycle : null) ?? 1;

        $slaClock = SlaClock::updateOrCreate(
            [
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'clock' => $clock,
                'cycle' => $cycle,
            ],
            [
                'organisation_id' => $project->organisation_id,
                'project_id' => $project->id,
                'sla_policy_id' => $policy->id,
                'started_at' => $start,
                'due_at' => $due,
                'state' => 'running',
                'completed_at' => null,
                'breached_at' => null,
                'reminders_sent' => [],
                'target_value' => $policy->target_value,
                'unit' => $policy->unit,
            ]
        );

        $this->audit->record(
            action: 'sla.started',
            entity: $subject,
            after: ['clock' => $clock, 'due_at' => $due->toIso8601String(), 'target' => "{$policy->target_value} {$policy->unit}"],
            summary: ucfirst($clock).' clock started',
        );

        return $slaClock;
    }

    public function dueAt(CarbonImmutable $from, SlaPolicy $policy, $calendar): CarbonImmutable
    {
        return match ($policy->unit) {
            'working_days' => $this->calendars->addWorkingDays($from, $policy->target_value, $calendar),
            'working_hours' => $this->calendars->addWorkingHours($from, $policy->target_value, $calendar),
            'calendar_hours' => $from->addHours($policy->target_value),
            default => $from->addDays($policy->target_value),
        };
    }

    /** Mark a clock met (or met late) when the milestone actually happens. */
    public function complete(Model $subject, string $clock, ?CarbonImmutable $at = null, ?int $cycle = null): ?SlaClock
    {
        $slaClock = $this->find($subject, $clock, $cycle);

        if (! $slaClock || $slaClock->isFinished()) {
            return $slaClock;
        }

        $at ??= CarbonImmutable::now();
        $late = $at->greaterThan($slaClock->due_at);

        $slaClock->forceFill([
            'completed_at' => $at,
            'state' => $late ? 'met_late' : 'met',
            'breached_at' => $late ? ($slaClock->breached_at ?? $slaClock->due_at) : null,
        ])->save();

        $this->audit->record(
            action: $late ? 'sla.met_late' : 'sla.met',
            entity: $subject,
            after: ['clock' => $clock, 'completed_at' => $at->toIso8601String()],
            summary: ucfirst($clock).' '.($late ? 'completed late' : 'completed on time'),
        );

        return $slaClock;
    }

    public function pause(Model $subject, string $clock, string $reason, ?int $cycle = null): ?SlaClock
    {
        $slaClock = $this->find($subject, $clock, $cycle);

        if (! $slaClock || $slaClock->state === 'paused' || $slaClock->isFinished()) {
            return $slaClock;
        }

        $slaClock->forceFill([
            'state' => 'paused',
            'paused_at' => now(),
            'pause_reason' => $reason,
        ])->save();

        $this->audit->record(
            action: 'sla.paused',
            entity: $subject,
            after: ['clock' => $clock, 'reason' => $reason],
            summary: ucfirst($clock).' clock paused: '.$reason,
        );

        return $slaClock;
    }

    public function resume(Model $subject, string $clock, ?int $cycle = null): ?SlaClock
    {
        $slaClock = $this->find($subject, $clock, $cycle);

        if (! $slaClock || $slaClock->state !== 'paused') {
            return $slaClock;
        }

        $pausedSeconds = (int) $slaClock->paused_at->diffInSeconds(now());
        $history = $slaClock->pause_history ?? [];
        $history[] = [
            'from' => $slaClock->paused_at->toIso8601String(),
            'to' => now()->toIso8601String(),
            'reason' => $slaClock->pause_reason,
            'seconds' => $pausedSeconds,
        ];

        $slaClock->forceFill([
            'state' => 'running',
            'due_at' => $slaClock->due_at->addSeconds($pausedSeconds),
            'paused_seconds_total' => $slaClock->paused_seconds_total + $pausedSeconds,
            'pause_history' => $history,
            'paused_at' => null,
            'pause_reason' => null,
        ])->save();

        $this->audit->record(
            action: 'sla.resumed',
            entity: $subject,
            after: ['clock' => $clock, 'paused_seconds' => $pausedSeconds, 'new_due_at' => $slaClock->due_at->toIso8601String()],
            summary: ucfirst($clock).' clock resumed',
        );

        return $slaClock;
    }

    public function cancel(Model $subject, string $clock, ?int $cycle = null): void
    {
        $slaClock = $this->find($subject, $clock, $cycle);

        $slaClock?->forceFill(['state' => 'cancelled'])->save();
    }

    /**
     * Evaluate one clock: at_risk at the configured thresholds, breached past
     * the due instant. Returns the reminder thresholds newly crossed.
     *
     * @return array{state:string,changed:bool,thresholds_crossed:array<int,int>}
     */
    public function evaluate(SlaClock $clock): array
    {
        if ($clock->isFinished() || $clock->state === 'paused') {
            return ['state' => $clock->state, 'changed' => false, 'thresholds_crossed' => []];
        }

        $previous = $clock->state;
        $percent = $clock->elapsedPercent();
        $thresholds = $clock->policy?->reminder_thresholds
            ?? config('sasa.sla.reminder_thresholds', [50, 80]);

        $sent = $clock->reminders_sent ?? [];
        $crossed = [];

        foreach ($thresholds as $threshold) {
            if ($percent >= $threshold && ! in_array($threshold, $sent, true)) {
                $crossed[] = (int) $threshold;
                $sent[] = (int) $threshold;
            }
        }

        $state = match (true) {
            now()->greaterThan($clock->due_at) => 'breached',
            $percent >= (float) max($thresholds ?: [80]) => 'at_risk',
            default => 'running',
        };

        $changed = $state !== $previous;

        $clock->forceFill([
            'state' => $state,
            'reminders_sent' => $sent,
            'breached_at' => $state === 'breached' ? ($clock->breached_at ?? $clock->due_at) : $clock->breached_at,
        ])->save();

        if ($changed) {
            $this->audit->record(
                action: 'sla.state_changed',
                entity: $clock->subject,
                before: ['state' => $previous],
                after: ['state' => $state, 'clock' => $clock->clock, 'elapsed_percent' => $percent],
                summary: sprintf('%s SLA is now %s', ucfirst($clock->clock), str_replace('_', ' ', $state)),
            );
        }

        return ['state' => $state, 'changed' => $changed, 'thresholds_crossed' => $crossed];
    }

    public function find(Model $subject, string $clock, ?int $cycle = null): ?SlaClock
    {
        $cycle ??= ($subject instanceof Grievance ? $subject->resolution_cycle : null) ?? 1;

        return SlaClock::query()
            ->acrossTenants()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('clock', $clock)
            ->where('cycle', $cycle)
            ->first();
    }

    /** Human-readable target, e.g. "7 working days". */
    public function describe(SlaClock $clock): string
    {
        return sprintf('%d %s', $clock->target_value, str_replace('_', ' ', (string) $clock->unit));
    }
}
