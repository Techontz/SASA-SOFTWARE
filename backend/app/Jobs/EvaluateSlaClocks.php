<?php

namespace App\Jobs;

use App\Domain\Grievance\GrievanceService;
use App\Domain\Notification\NotificationDispatcher;
use App\Domain\Notification\NotificationEvents;
use App\Domain\Sla\SlaEngine;
use App\Models\Grievance;
use App\Models\SlaClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The background sweep the SLA engine relies on: it re-evaluates every open
 * clock, sends the configured reminders, escalates on breach and writes an
 * audit event for every flag change.
 */
class EvaluateSlaClocks implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?int $projectId = null) {}

    public function handle(
        SlaEngine $sla,
        NotificationDispatcher $notifications,
        GrievanceService $grievances,
    ): void {
        $evaluated = 0;
        $breached = 0;

        SlaClock::query()
            ->acrossTenants()
            ->whereIn('state', ['running', 'at_risk'])
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            ->with(['policy', 'subject.project', 'subject.assignee'])
            ->chunkById(200, function ($clocks) use ($sla, $notifications, $grievances, &$evaluated, &$breached) {
                foreach ($clocks as $clock) {
                    $subject = $clock->subject;

                    if (! $subject || ! $subject->project) {
                        continue;
                    }

                    $before = $clock->state;
                    $result = $sla->evaluate($clock);
                    $evaluated++;

                    $payload = [
                        'reference' => $subject->reference ?? '',
                        'title' => $subject->title ?? '',
                        'clock' => str_replace('_', ' ', $clock->clock),
                        'due_at' => $clock->due_at?->toDayDateTimeString(),
                        'percent' => (int) $clock->elapsedPercent(),
                        'severity' => $subject->severity ?? null,
                        'url' => '/grievances/'.$subject->id,
                    ];

                    $recipients = array_filter([$subject->assigned_to_id ?? null]);

                    foreach ($result['thresholds_crossed'] as $threshold) {
                        $notifications->dispatch(
                            eventKey: NotificationEvents::GRIEVANCE_SLA_AT_RISK,
                            project: $subject->project,
                            payload: array_merge($payload, ['percent' => $threshold]),
                            extraUserIds: $recipients,
                            severity: 'warning',
                        );
                    }

                    if ($result['state'] === 'breached' && $before !== 'breached') {
                        $breached++;

                        $notifications->dispatch(
                            eventKey: NotificationEvents::GRIEVANCE_SLA_BREACHED,
                            project: $subject->project,
                            payload: $payload,
                            extraUserIds: $recipients,
                            severity: 'danger',
                        );

                        // A breach escalates automatically to the next level.
                        if ($subject instanceof Grievance && $subject->isOpen()) {
                            $grievances->escalate(
                                $subject,
                                'sla_breach',
                                sprintf('The %s deadline passed on %s.', $clock->clock, $clock->due_at?->toDayDateTimeString()),
                                null,
                                $clock->policy?->escalate_to_role_id,
                            );
                        }
                    }

                    // Keep the denormalised snapshot on the case in step.
                    if ($subject instanceof Grievance) {
                        [$stateColumn, $dueColumn] = match ($clock->clock) {
                            'acknowledgement' => ['acknowledgement_sla_state', 'acknowledgement_due_at'],
                            'resolution' => ['resolution_sla_state', 'resolution_due_at'],
                            default => [null, null],
                        };

                        if ($stateColumn) {
                            $subject->forceFill([
                                $stateColumn => $result['state'],
                                $dueColumn => $clock->due_at,
                            ])->saveQuietly();
                        }
                    }
                }
            });

        Log::info('sla.sweep_complete', [
            'evaluated' => $evaluated,
            'newly_breached' => $breached,
            'project_id' => $this->projectId,
        ]);
    }
}
