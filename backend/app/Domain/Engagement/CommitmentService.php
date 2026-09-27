<?php

namespace App\Domain\Engagement;

use App\Domain\Audit\AuditLogger;
use App\Domain\Configuration\ConfigurationRegistry;
use App\Domain\Notification\NotificationDispatcher;
use App\Domain\Notification\NotificationEvents;
use App\Models\Commitment;
use App\Models\Project;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\DB;

/**
 * The commitments register — a first-class entity presented inside Module 2,
 * because it is the accountability tail of an engagement rather than a
 * separate discipline.
 */
final class CommitmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly ConfigurationRegistry $configuration,
    ) {}

    public function create(Project $project, array $data): Commitment
    {
        return DB::transaction(function () use ($project, $data) {
            $commitment = new Commitment;
            $commitment->organisation_id = $project->organisation_id;
            $commitment->project_id = $project->id;
            $commitment->fill(collect($data)->only([
                'client_uuid', 'engagement_id', 'concern_id', 'grievance_id', 'location_id',
                'commitment_text', 'source_type', 'source_date', 'owner_id', 'owner_team',
                'due_date', 'priority', 'risk_level', 'status', 'evidence_notes', 'notes', 'custom_fields',
            ])->all());
            $commitment->captured_at = $data['captured_at'] ?? now();
            $commitment->synced_at = now();
            $commitment->save();

            if (! empty($data['stakeholder_ids'])) {
                $commitment->stakeholders()->sync($data['stakeholder_ids']);
            }

            return $commitment->fresh(['owner', 'stakeholders', 'engagement']);
        });
    }

    public function update(Commitment $commitment, array $data): Commitment
    {
        return DB::transaction(function () use ($commitment, $data) {
            $commitment->fill(collect($data)->only([
                'commitment_text', 'location_id', 'owner_id', 'owner_team', 'due_date',
                'priority', 'risk_level', 'evidence_notes', 'notes', 'custom_fields',
            ])->all());
            $commitment->save();

            if (array_key_exists('stakeholder_ids', $data)) {
                $commitment->stakeholders()->sync($data['stakeholder_ids'] ?? []);
            }

            return $commitment->fresh(['owner', 'stakeholders']);
        });
    }

    public function changeStatus(Commitment $commitment, string $status, array $data = []): Commitment
    {
        if (! in_array($status, Commitment::STATUSES, true)) {
            throw new DomainRuleException('That is not a valid commitment status.', 'invalid_status');
        }

        if ($status === 'fulfilled' && empty($data['completed_on'])) {
            $data['completed_on'] = today();
        }

        $before = $commitment->status;

        $commitment->forceFill(array_filter([
            'status' => $status,
            'completed_on' => $data['completed_on'] ?? $commitment->completed_on,
            'evidence_notes' => $data['evidence_notes'] ?? $commitment->evidence_notes,
        ], fn ($value) => $value !== null))->save();

        $this->audit->record(
            action: 'commitment.status_changed',
            entity: $commitment,
            before: ['status' => $before],
            after: ['status' => $status],
            summary: "Status changed from {$before} to {$status}",
        );

        return $commitment->fresh();
    }

    /** Verification is a separate act from completion, and by a different person. */
    public function verify(Commitment $commitment, string $verificationStatus, ?string $notes = null): Commitment
    {
        if (! in_array($verificationStatus, ['verified', 'disputed', 'unverified'], true)) {
            throw new DomainRuleException('Verification must be verified, disputed or unverified.', 'invalid_verification');
        }

        if ($verificationStatus === 'verified' && $commitment->status !== 'fulfilled') {
            throw new DomainRuleException(
                'Mark the commitment as fulfilled before verifying it.',
                'not_fulfilled'
            );
        }

        $commitment->forceFill([
            'verification_status' => $verificationStatus,
            'verified_by' => auth()->id(),
            'verified_at' => now(),
            'notes' => $notes ?? $commitment->notes,
        ])->save();

        $this->audit->record(
            action: 'commitment.verified',
            entity: $commitment,
            after: ['verification_status' => $verificationStatus],
            summary: $notes ?? "Verification recorded as {$verificationStatus}",
        );

        return $commitment->fresh('verifier');
    }

    /**
     * Scheduler sweep: flip due commitments to overdue and fire the reminders
     * configured for this project (default T−14, T−3, due date, T+7, T+30).
     *
     * @return array{overdue:int,reminders:int}
     */
    public function runReminderSweep(?int $projectId = null): array
    {
        $offsets = $this->offsets($projectId);
        $overdue = 0;
        $reminders = 0;

        Commitment::query()
            ->acrossTenants()
            ->with(['project', 'owner'])
            ->whereIn('status', ['open', 'in_progress', 'overdue'])
            ->whereNotNull('due_date')
            ->whereNull('archived_at')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->chunkById(200, function ($commitments) use (&$overdue, &$reminders, $offsets) {
                foreach ($commitments as $commitment) {
                    if (! $commitment->project) {
                        continue;
                    }

                    $daysUntilDue = (int) today()->diffInDays($commitment->due_date, false);

                    if ($daysUntilDue < 0 && $commitment->status !== 'overdue') {
                        $commitment->forceFill(['status' => 'overdue'])->save();
                        $overdue++;
                    }

                    $sent = $commitment->reminders_sent ?? [];
                    $offsetProject = $this->offsets($commitment->project_id) ?: $offsets;

                    foreach ($offsetProject as $offset) {
                        // offset -14 means "14 days before due"
                        if ($daysUntilDue > -$offset || in_array($offset, $sent, true)) {
                            continue;
                        }

                        $event = $offset < 0
                            ? NotificationEvents::COMMITMENT_DUE_SOON
                            : ($offset === 0 ? NotificationEvents::COMMITMENT_OVERDUE : NotificationEvents::COMMITMENT_ESCALATED);

                        $this->notifications->dispatch(
                            eventKey: $event,
                            project: $commitment->project,
                            payload: [
                                'reference' => $commitment->reference,
                                'commitment_text' => mb_substr($commitment->commitment_text, 0, 200),
                                'due_date' => $commitment->due_date->toFormattedDateString(),
                                'days_overdue' => max(0, -$daysUntilDue),
                                'risk_level' => $commitment->risk_level,
                                'url' => "/commitments/{$commitment->id}",
                            ],
                            extraUserIds: array_filter([$commitment->owner_id]),
                            severity: $offset >= 7 ? 'warning' : 'info',
                        );

                        $sent[] = $offset;
                        $reminders++;
                    }

                    // High-risk commitments escalate immediately on becoming overdue.
                    if ($commitment->risk_level === 'high'
                        && $daysUntilDue < 0
                        && ! in_array('high_risk', $sent, true)
                        && config('sasa.commitments.high_risk_escalates_immediately')) {
                        $this->notifications->dispatch(
                            eventKey: NotificationEvents::COMMITMENT_ESCALATED,
                            project: $commitment->project,
                            payload: [
                                'reference' => $commitment->reference,
                                'commitment_text' => mb_substr($commitment->commitment_text, 0, 200),
                                'days_overdue' => -$daysUntilDue,
                                'url' => "/commitments/{$commitment->id}",
                            ],
                            extraUserIds: array_filter([$commitment->owner_id]),
                            severity: 'danger',
                        );

                        $sent[] = 'high_risk';
                        $reminders++;
                    }

                    if ($sent !== ($commitment->reminders_sent ?? [])) {
                        $commitment->forceFill(['reminders_sent' => array_values(array_unique($sent, SORT_REGULAR))])->save();
                    }
                }
            });

        return ['overdue' => $overdue, 'reminders' => $reminders];
    }

    /** @return array<int,int> */
    private function offsets(?int $projectId): array
    {
        if (! $projectId) {
            return config('sasa.commitments.reminder_offsets', [-14, -3, 0, 7, 30]);
        }

        $project = Project::find($projectId);

        $stored = $this->configuration->get('commitments', $project?->organisation_id, $projectId, []);

        return $stored['reminder_offsets'] ?? config('sasa.commitments.reminder_offsets', [-14, -3, 0, 7, 30]);
    }
}
