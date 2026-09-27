<?php

namespace App\Domain\Grievance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Notification\NotificationDispatcher;
use App\Domain\Notification\NotificationEvents;
use App\Domain\Sla\SlaEngine;
use App\Models\Assignment;
use App\Models\Communication;
use App\Models\Grievance;
use App\Models\GrievanceEscalation;
use App\Models\GrievanceResolutionCycle;
use App\Models\User;
use App\Support\DomainRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The grievance lifecycle:
 *
 *   Intake → Classification → Assignment → Acknowledgement → Investigation
 *   → Corrective action → Resolution → Complainant confirmation → Closure
 *
 * Reopening keeps the original case ID and opens a second resolution cycle.
 */
final class GrievanceService
{
    public function __construct(
        private readonly SlaEngine $sla,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly IntakeEngine $intake,
    ) {}

    // ------------------------------------------------------------ classification

    public function classify(Grievance $grievance, array $data): Grievance
    {
        return DB::transaction(function () use ($grievance, $data) {
            $before = [
                'category_id' => $grievance->category_id,
                'subcategory_id' => $grievance->subcategory_id,
                'severity' => $grievance->severity,
            ];

            $grievance->fill(collect($data)->only(['category_id', 'subcategory_id', 'severity'])->all());
            $grievance->classification_confirmed = true;
            $grievance->classified_by = auth()->id();
            $grievance->classified_at = now();

            $this->intake->applyCategoryRestrictions($grievance);

            if ($grievance->status === 'new') {
                $grievance->status = 'classified';
            }

            $grievance->save();

            // Severity or category may change which SLA policy applies.
            $this->sla->start($grievance, 'resolution', CarbonImmutable::parse($grievance->received_at));
            $this->intake->refreshSlaSnapshot($grievance);

            $this->audit->record(
                action: 'grievance.classified',
                entity: $grievance,
                before: $before,
                after: [
                    'category_id' => $grievance->category_id,
                    'subcategory_id' => $grievance->subcategory_id,
                    'severity' => $grievance->severity,
                ],
                summary: 'Classification confirmed by a person',
            );

            if ($grievance->severity >= config('sasa.grievances.management_alert_from_severity', 4)
                && ($before['severity'] ?? 0) < config('sasa.grievances.management_alert_from_severity', 4)) {
                $this->notifications->dispatch(
                    eventKey: NotificationEvents::GRIEVANCE_HIGH_SEVERITY,
                    project: $grievance->project,
                    payload: $this->intake->notificationPayload($grievance),
                    severity: 'danger',
                );
            }

            return $grievance->fresh(['category', 'subcategory']);
        });
    }

    // ----------------------------------------------------------------- assignment

    public function assign(Grievance $grievance, ?int $userId, ?string $team = null, ?string $reason = null): Grievance
    {
        $assignee = $userId ? User::find($userId) : null;

        if ($userId && ! $assignee) {
            throw new DomainRuleException('That person could not be found.', 'assignee_not_found');
        }

        if ($assignee && ! $assignee->membershipFor($grievance->project_id)) {
            throw new DomainRuleException(
                'That person is not a member of this project, so the case cannot be assigned to them.',
                'assignee_not_member'
            );
        }

        // A restricted case can only be held by someone in its handling group.
        if ($grievance->is_restricted && $assignee) {
            $membership = $assignee->membershipFor($grievance->project_id);
            $groups = $membership?->handling_groups ?? [];

            if (! $assignee->is_system_admin && array_intersect($grievance->handling_groups ?? [], $groups) === []) {
                throw new DomainRuleException(
                    'This case is restricted. Only members of its handling group can be assigned to it.',
                    'assignee_not_in_handling_group',
                    403
                );
            }
        }

        return DB::transaction(function () use ($grievance, $assignee, $team, $reason) {
            $wasAssigned = $grievance->assigned_to_id !== null;
            $previous = $grievance->assigned_to_id;

            Assignment::where('grievance_id', $grievance->id)
                ->where('is_current', true)
                ->update(['is_current' => false, 'unassigned_at' => now()]);

            Assignment::create([
                'organisation_id' => $grievance->organisation_id,
                'project_id' => $grievance->project_id,
                'grievance_id' => $grievance->id,
                'assigned_to_id' => $assignee?->id,
                'assigned_team' => $team,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
                'reason' => $reason,
                'is_current' => true,
            ]);

            $grievance->forceFill([
                'assigned_to_id' => $assignee?->id,
                'assigned_team' => $team,
                'assigned_at' => now(),
                'status' => in_array($grievance->status, ['new', 'classified'], true) ? 'assigned' : $grievance->status,
            ])->save();

            $this->audit->record(
                action: $wasAssigned ? 'grievance.reassigned' : 'grievance.assigned',
                entity: $grievance,
                before: ['assigned_to_id' => $previous],
                after: ['assigned_to_id' => $assignee?->id, 'team' => $team],
                summary: $assignee
                    ? "Assigned to {$assignee->name}".($reason ? ": {$reason}" : '')
                    : 'Unassigned',
            );

            $this->notifications->dispatch(
                eventKey: $wasAssigned ? NotificationEvents::GRIEVANCE_REASSIGNED : NotificationEvents::GRIEVANCE_ASSIGNED,
                project: $grievance->project,
                payload: array_merge($this->intake->notificationPayload($grievance), [
                    'assignee' => $assignee?->name ?? 'nobody',
                ]),
                extraUserIds: array_filter([$assignee?->id]),
            );

            return $grievance->fresh('assignee');
        });
    }

    // ------------------------------------------------------------ acknowledgement

    public function acknowledge(Grievance $grievance, string $method, ?string $body = null): Grievance
    {
        if ($grievance->acknowledged_at) {
            return $grievance;
        }

        return DB::transaction(function () use ($grievance, $method, $body) {
            $grievance->forceFill([
                'acknowledged_at' => now(),
                'acknowledgement_method' => $method,
                'status' => $grievance->status === 'assigned' || $grievance->status === 'classified' || $grievance->status === 'new'
                    ? 'acknowledged'
                    : $grievance->status,
            ])->save();

            $this->sla->complete($grievance, 'acknowledgement');
            $this->intake->refreshSlaSnapshot($grievance);

            if ($body !== null && $grievance->acknowledgement_possible) {
                $this->logCommunication($grievance, 'acknowledgement', $body, $method);
            }

            $this->audit->record(
                action: 'grievance.acknowledged',
                entity: $grievance,
                after: ['method' => $method],
                summary: "Complainant acknowledged via {$method}",
            );

            return $grievance->fresh();
        });
    }

    // ------------------------------------------------------------- investigation

    public function startInvestigation(Grievance $grievance, ?string $note = null): Grievance
    {
        $grievance->forceFill([
            'status' => 'under_investigation',
            'investigation_started_at' => $grievance->investigation_started_at ?? now(),
        ])->save();

        $this->sla->start($grievance, 'investigation');

        $this->audit->record(
            action: 'grievance.investigation_started',
            entity: $grievance,
            summary: $note ?? 'Investigation opened',
        );

        return $grievance->fresh();
    }

    public function recordInvestigation(Grievance $grievance, array $data): Grievance
    {
        $grievance->forceFill(array_filter([
            'investigation_summary' => $data['investigation_summary'] ?? null,
            'investigation_findings' => $data['investigation_findings'] ?? null,
            'investigation_completed_at' => ! empty($data['completed']) ? now() : $grievance->investigation_completed_at,
            'corrective_action' => $data['corrective_action'] ?? null,
            'corrective_action_owner_id' => $data['corrective_action_owner_id'] ?? null,
            'corrective_action_due' => $data['corrective_action_due'] ?? null,
        ], fn ($value) => $value !== null))->save();

        if (! empty($data['completed'])) {
            $this->sla->complete($grievance, 'investigation');

            if ($grievance->status === 'under_investigation') {
                $grievance->forceFill(['status' => 'action_pending'])->save();
            }
        }

        $this->audit->record(
            action: 'grievance.investigation_updated',
            entity: $grievance,
            after: ['completed' => (bool) ($data['completed'] ?? false)],
            summary: 'Investigation record updated by a person',
        );

        return $grievance->fresh();
    }

    // ------------------------------------------------------------------ resolution

    public function resolve(Grievance $grievance, string $summary, ?string $correctiveAction = null): Grievance
    {
        if (in_array($grievance->status, ['closed', 'rejected', 'withdrawn'], true)) {
            throw new DomainRuleException('This case is already closed. Reopen it before resolving again.', 'already_closed');
        }

        if (trim($summary) === '') {
            throw new DomainRuleException('Describe how the case was resolved before marking it resolved.', 'summary_required');
        }

        return DB::transaction(function () use ($grievance, $summary, $correctiveAction) {
            $grievance->forceFill([
                'resolution_summary' => $summary,
                'corrective_action' => $correctiveAction ?? $grievance->corrective_action,
                'resolved_at' => now(),
                'resolved_by' => auth()->id(),
                'status' => 'awaiting_confirmation',
            ])->save();

            GrievanceResolutionCycle::updateOrCreate(
                ['grievance_id' => $grievance->id, 'cycle_number' => $grievance->resolution_cycle],
                [
                    'opened_at' => $grievance->last_reopened_at ?? $grievance->received_at,
                    'resolution_summary' => $summary,
                    'resolved_at' => now(),
                    'resolved_by' => auth()->id(),
                ]
            );

            $this->sla->complete($grievance, 'resolution');
            $this->intake->refreshSlaSnapshot($grievance);

            $this->audit->record(
                action: 'grievance.resolved',
                entity: $grievance,
                after: ['cycle' => $grievance->resolution_cycle],
                summary: 'Resolution recorded, awaiting the complainant\'s confirmation',
            );

            $this->notifications->dispatch(
                eventKey: NotificationEvents::GRIEVANCE_RESOLVED,
                project: $grievance->project,
                payload: $this->intake->notificationPayload($grievance),
                extraUserIds: array_filter([$grievance->assigned_to_id]),
            );

            return $grievance->fresh();
        });
    }

    /** The complainant's own answer, recorded as a distinct act. */
    public function recordComplainantResponse(Grievance $grievance, string $response, ?string $note = null): Grievance
    {
        if (! in_array($response, ['accepted', 'rejected', 'no_response', 'not_contactable'], true)) {
            throw new DomainRuleException('That is not a valid complainant response.', 'invalid_response');
        }

        $grievance->forceFill([
            'complainant_response' => $response,
            'complainant_responded_at' => now(),
        ])->save();

        GrievanceResolutionCycle::where('grievance_id', $grievance->id)
            ->where('cycle_number', $grievance->resolution_cycle)
            ->update(['complainant_response' => $response]);

        $this->audit->record(
            action: 'grievance.complainant_response',
            entity: $grievance,
            after: ['response' => $response],
            summary: $note ?? "Complainant response recorded as {$response}",
        );

        return $grievance->fresh();
    }

    public function close(Grievance $grievance, ?string $notes = null): Grievance
    {
        if (! $grievance->resolved_at && $grievance->status !== 'rejected') {
            throw new DomainRuleException(
                'Record how the case was resolved before closing it.',
                'not_resolved'
            );
        }

        return DB::transaction(function () use ($grievance, $notes) {
            $grievance->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'closure_notes' => $notes,
            ])->save();

            GrievanceResolutionCycle::where('grievance_id', $grievance->id)
                ->where('cycle_number', $grievance->resolution_cycle)
                ->update(['closed_at' => now()]);

            foreach (['acknowledgement', 'investigation', 'resolution'] as $clock) {
                $this->sla->cancel($grievance, $clock);
            }

            $this->audit->record(
                action: 'grievance.closed',
                entity: $grievance,
                after: ['cycle' => $grievance->resolution_cycle],
                summary: $notes ?? 'Case closed',
            );

            $this->notifications->dispatch(
                eventKey: NotificationEvents::GRIEVANCE_CLOSED,
                project: $grievance->project,
                payload: array_merge($this->intake->notificationPayload($grievance), [
                    'closed_at' => now()->toFormattedDateString(),
                ]),
                extraUserIds: array_filter([$grievance->assigned_to_id]),
            );

            return $grievance->fresh();
        });
    }

    /**
     * Reopen. The source status list has no reopened state; without one,
     * dissatisfaction produces duplicate cases and the resolution statistics
     * flatter the project. The case ID is retained and a new cycle begins.
     */
    public function reopen(Grievance $grievance, string $reason): Grievance
    {
        if (trim($reason) === '') {
            throw new DomainRuleException('Give a reason for reopening this case.', 'reason_required');
        }

        if ($grievance->isOpen() && $grievance->status !== 'awaiting_confirmation') {
            throw new DomainRuleException('This case is still open — there is nothing to reopen.', 'not_closed');
        }

        return DB::transaction(function () use ($grievance, $reason) {
            $newCycle = $grievance->resolution_cycle + 1;

            $grievance->forceFill([
                'status' => 'reopened',
                'resolution_cycle' => $newCycle,
                'reopen_count' => $grievance->reopen_count + 1,
                'last_reopened_at' => now(),
                'resolved_at' => null,
                'resolved_by' => null,
                'closed_at' => null,
                'closed_by' => null,
                'complainant_response' => null,
                'complainant_responded_at' => null,
            ])->save();

            GrievanceResolutionCycle::create([
                'grievance_id' => $grievance->id,
                'cycle_number' => $newCycle,
                'opened_at' => now(),
                'reopen_reason' => $reason,
            ]);

            // A fresh resolution clock for the new cycle — the first cycle's
            // clock stays on the record, so both are reportable.
            $this->sla->start($grievance, 'resolution', CarbonImmutable::now(), $newCycle);
            $this->intake->refreshSlaSnapshot($grievance);

            $this->audit->record(
                action: 'grievance.reopened',
                entity: $grievance,
                after: ['cycle' => $newCycle],
                summary: "Reopened: {$reason}",
            );

            $this->notifications->dispatch(
                eventKey: NotificationEvents::GRIEVANCE_REOPENED,
                project: $grievance->project,
                payload: array_merge($this->intake->notificationPayload($grievance), [
                    'cycle' => $newCycle,
                    'reason' => $reason,
                ]),
                extraUserIds: array_filter([$grievance->assigned_to_id]),
                severity: 'warning',
            );

            return $grievance->fresh('cycles');
        });
    }

    // ------------------------------------------------------------------ escalation

    public function escalate(Grievance $grievance, string $trigger, ?string $reason = null, ?int $toUserId = null, ?int $toRoleId = null): Grievance
    {
        return DB::transaction(function () use ($grievance, $trigger, $reason, $toUserId, $toRoleId) {
            $from = $grievance->escalation_level;
            $to = $from + 1;

            GrievanceEscalation::create([
                'organisation_id' => $grievance->organisation_id,
                'project_id' => $grievance->project_id,
                'grievance_id' => $grievance->id,
                'from_level' => $from,
                'to_level' => $to,
                'trigger' => $trigger,
                'reason' => $reason,
                'escalated_by' => auth()->id(),
                'escalated_to_id' => $toUserId,
                'escalated_to_role_id' => $toRoleId,
                'escalated_at' => now(),
            ]);

            $grievance->forceFill([
                'escalation_level' => $to,
                'escalated_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'grievance.escalated',
                entity: $grievance,
                before: ['escalation_level' => $from],
                after: ['escalation_level' => $to, 'trigger' => $trigger],
                summary: $reason ?? "Escalated ({$trigger})",
            );

            $this->notifications->dispatch(
                eventKey: NotificationEvents::GRIEVANCE_ESCALATED,
                project: $grievance->project,
                payload: array_merge($this->intake->notificationPayload($grievance), [
                    'trigger' => str_replace('_', ' ', $trigger),
                    'reason' => $reason ?? '',
                ]),
                extraUserIds: array_filter([$toUserId, $grievance->assigned_to_id]),
                severity: 'danger',
            );

            return $grievance->fresh();
        });
    }

    // -------------------------------------------------------------- communication

    public function logCommunication(
        Grievance $grievance,
        string $templateKey,
        string $body,
        string $channel,
        string $direction = 'outbound',
        ?string $subject = null,
    ): Communication {
        return Communication::create([
            'organisation_id' => $grievance->organisation_id,
            'project_id' => $grievance->project_id,
            'grievance_id' => $grievance->id,
            'direction' => $direction,
            'channel' => $channel,
            'template_key' => $templateKey,
            'recipient' => $grievance->isAnonymous() ? null : ($grievance->complainant_phone ?? $grievance->complainant_email),
            'subject' => $subject,
            'body' => $body,
            'language' => $grievance->complainant_language ?? 'en',
            'status' => $grievance->acknowledgement_possible ? 'sent' : 'not_possible',
            'sent_at' => now(),
            'sent_by' => auth()->id(),
        ]);
    }

    public function withdraw(Grievance $grievance, string $reason): Grievance
    {
        $grievance->forceFill([
            'status' => 'withdrawn',
            'closed_at' => now(),
            'closed_by' => auth()->id(),
            'closure_notes' => $reason,
        ])->save();

        foreach (['acknowledgement', 'investigation', 'resolution'] as $clock) {
            $this->sla->cancel($grievance, $clock);
        }

        $this->audit->record(
            action: 'grievance.withdrawn',
            entity: $grievance,
            summary: "Withdrawn: {$reason}",
        );

        return $grievance->fresh();
    }
}
