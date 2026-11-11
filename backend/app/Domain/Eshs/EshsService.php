<?php

namespace App\Domain\Eshs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\EshsAnomaly;
use App\Models\Project;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The assurance workflow, as the client wrote it:
 *
 *   raise (client / supervision)  →  open
 *   contractor starts the action  →  in progress
 *   contractor submits evidence   →  in progress, awaiting verification
 *   supervision returns it        →  in progress, with a reason
 *   supervision closes it         →  closed (needs closure evidence on file)
 *
 * Who may do each step is the policy's job; whether the step makes sense for
 * the anomaly's current state is decided here.
 */
final class EshsService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EshsNotifier $notifier,
        private readonly TenantContext $context,
    ) {}

    /** Every ESHS query starts here, so contractor scoping cannot be forgotten. */
    public function query(Project $project): Builder
    {
        return EshsAnomaly::query()
            ->where('eshs_anomalies.project_id', $project->id)
            ->forContractor(EshsAnomaly::contractorScopeFor($this->context->membership(), $this->context->roleKey()));
    }

    public function raise(Project $project, array $data): EshsAnomaly
    {
        $anomaly = DB::transaction(function () use ($project, $data) {
            $anomaly = new EshsAnomaly;
            $anomaly->organisation_id = $project->organisation_id;
            $anomaly->project_id = $project->id;
            $anomaly->fill($data);
            $anomaly->raised_on = $data['raised_on'] ?? today();
            $anomaly->status = 'open';
            $anomaly->raised_by = auth()->id();
            $anomaly->save();

            return $anomaly;
        });

        $this->notifier->raised($anomaly);

        return $anomaly->fresh();
    }

    /** The raiser's fields. Nobody edits a closed anomaly. */
    public function update(EshsAnomaly $anomaly, array $data): EshsAnomaly
    {
        $this->guardNotClosed($anomaly);

        return DB::transaction(function () use ($anomaly, $data) {
            $anomaly->fill($data)->save();

            return $anomaly->fresh();
        });
    }

    /**
     * The contractor's update: the action they are taking, who owns it, and
     * how far it has got. Moves an open anomaly to in progress.
     */
    public function recordProgress(EshsAnomaly $anomaly, array $data): EshsAnomaly
    {
        $this->guardNotClosed($anomaly);

        return DB::transaction(function () use ($anomaly, $data) {
            $before = $anomaly->status;

            $anomaly->fill(collect($data)->only([
                'contractor_action', 'action_owner_name', 'action_owner_email', 'action_owner_phone', 'progress_notes',
            ])->all());
            $anomaly->status = 'in_progress';
            $anomaly->save();

            $this->audit->record(
                action: 'eshs_anomaly.progress_updated',
                entity: $anomaly,
                before: ['status' => $before],
                after: ['status' => 'in_progress'],
                summary: $before === 'open'
                    ? 'Contractor started the action'
                    : 'Contractor updated progress',
                context: array_filter(['notes' => $data['progress_notes'] ?? null]),
            );

            return $anomaly->fresh();
        });
    }

    public function submitForVerification(EshsAnomaly $anomaly, ?string $notes): EshsAnomaly
    {
        $this->guardNotClosed($anomaly);

        if ($anomaly->isAwaitingVerification()) {
            throw new DomainRuleException('This anomaly is already waiting for verification.', 'already_submitted');
        }

        if ($anomaly->closureEvidenceCount() === 0) {
            throw new DomainRuleException(
                'Upload at least one piece of closure evidence before submitting it for verification.',
                'closure_evidence_required'
            );
        }

        $anomaly = DB::transaction(function () use ($anomaly, $notes) {
            $anomaly->forceFill([
                'status' => 'in_progress',
                'evidence_submitted_at' => now(),
                'evidence_submitted_by' => auth()->id(),
                'closure_notes' => $notes ?? $anomaly->closure_notes,
                'returned_at' => null,
                'return_reason' => null,
            ])->save();

            $this->audit->record(
                action: 'eshs_anomaly.evidence_submitted',
                entity: $anomaly,
                summary: 'Closure evidence submitted for verification',
                context: array_filter(['notes' => $notes]),
            );

            return $anomaly->fresh();
        });

        $this->notifier->evidenceSubmitted($anomaly);

        return $anomaly;
    }

    /** The supervision team sends it back to the contractor, with a reason. */
    public function returnToContractor(EshsAnomaly $anomaly, string $reason): EshsAnomaly
    {
        $this->guardNotClosed($anomaly);

        if (! $anomaly->isAwaitingVerification()) {
            throw new DomainRuleException(
                'Only an anomaly that is waiting for verification can be returned.',
                'not_awaiting_verification'
            );
        }

        $anomaly = DB::transaction(function () use ($anomaly, $reason) {
            $anomaly->forceFill([
                'evidence_submitted_at' => null,
                'evidence_submitted_by' => null,
                'returned_at' => now(),
                'return_reason' => $reason,
            ])->save();

            $this->audit->record(
                action: 'eshs_anomaly.returned',
                entity: $anomaly,
                summary: 'Returned to the contractor',
                context: ['reason' => $reason],
            );

            return $anomaly->fresh();
        });

        $this->notifier->returned($anomaly);

        return $anomaly;
    }

    /** Verification and closure, by the supervision team, on the evidence. */
    public function close(EshsAnomaly $anomaly, ?string $notes, ?string $closedOn = null): EshsAnomaly
    {
        $this->guardNotClosed($anomaly);

        if ($anomaly->closureEvidenceCount() === 0) {
            throw new DomainRuleException(
                'There is no closure evidence on file. An anomaly is closed on evidence, so the contractor must upload it first.',
                'closure_evidence_required'
            );
        }

        $anomaly = DB::transaction(function () use ($anomaly, $notes, $closedOn) {
            $anomaly->forceFill([
                'status' => 'closed',
                'closed_on' => $closedOn ?? today(),
                'closed_by' => auth()->id(),
                'verification_notes' => $notes,
            ])->save();

            $this->audit->record(
                action: 'eshs_anomaly.closed',
                entity: $anomaly,
                summary: 'Verified and closed',
                context: array_filter(['notes' => $notes]),
            );

            return $anomaly->fresh();
        });

        $this->notifier->closed($anomaly);

        return $anomaly;
    }

    /**
     * The anomaly's story, read from the append-only audit trail: raised,
     * started, evidence uploaded and submitted, returned, closed.
     *
     * @return array<int,array{id:int,action:string,summary:?string,user:?string,at:?string,detail:?string}>
     */
    public function history(EshsAnomaly $anomaly): array
    {
        $labels = [
            'eshs_anomaly.created' => 'Raised',
            'eshs_anomaly.progress_updated' => null,
            'eshs_anomaly.evidence_submitted' => null,
            'eshs_anomaly.returned' => null,
            'eshs_anomaly.closed' => null,
            'eshs_anomaly.archived' => 'Archived',
            'attachment.uploaded' => null,
        ];

        return AuditLog::query()
            ->where('entity_type', class_basename(EshsAnomaly::class))
            ->where('entity_id', $anomaly->id)
            ->where('project_id', $anomaly->project_id)
            ->whereIn('action', array_keys($labels))
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->map(function (AuditLog $log) use ($labels) {
                $context = (array) ($log->context ?? []);
                $after = (array) ($log->after ?? []);

                $detail = $context['reason'] ?? $context['notes'] ?? null;
                if ($log->action === 'attachment.uploaded') {
                    $kind = $after['kind'] ?? null;
                    $detail = ($kind === EshsAnomaly::KIND_CLOSURE_EVIDENCE ? 'Closure evidence: ' : 'Evidence: ').($after['name'] ?? '');
                }

                return [
                    'id' => $log->id,
                    'action' => str_replace(['eshs_anomaly.', 'attachment.'], ['', 'attachment_'], $log->action),
                    'summary' => $labels[$log->action] ?? $log->summary,
                    'user' => $log->user_name,
                    'at' => $log->created_at?->toIso8601String(),
                    'detail' => is_string($detail) ? $detail : null,
                ];
            })
            ->all();
    }

    public function archive(EshsAnomaly $anomaly): void
    {
        DB::transaction(fn () => $anomaly->archive());
    }

    private function guardNotClosed(EshsAnomaly $anomaly): void
    {
        if ($anomaly->isClosed()) {
            throw new DomainRuleException('This anomaly is closed. A closed anomaly cannot be changed.', 'anomaly_closed');
        }

        if ($anomaly->archived_at !== null) {
            throw new DomainRuleException('This anomaly is archived and cannot be changed.', 'anomaly_archived');
        }
    }
}
