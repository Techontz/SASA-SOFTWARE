<?php

namespace App\Domain\Engagement;

use App\Domain\Audit\AuditLogger;
use App\Models\Concern;
use App\Models\Project;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Concerns are first-class records — and the thing a grievance is escalated
 * FROM. Escalation copies the substance across so nobody retypes a concern
 * that was already captured in the field.
 */
final class ConcernService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Project $project, array $data): Concern
    {
        $concern = new Concern;
        $concern->organisation_id = $project->organisation_id;
        $concern->project_id = $project->id;
        $concern->fill(collect($data)->only([
            'client_uuid', 'engagement_id', 'stakeholder_id', 'location_id', 'grievance_category_id',
            'title', 'description', 'raised_by', 'raised_on', 'severity_hint', 'status',
            'response', 'owner_id',
        ])->all());
        $concern->raised_on = $data['raised_on'] ?? today();
        $concern->title = $data['title'] ?? mb_substr((string) ($data['description'] ?? 'Concern'), 0, 120);
        $concern->captured_at = $data['captured_at'] ?? now();
        $concern->synced_at = now();
        $concern->save();

        return $concern->fresh(['engagement', 'stakeholder', 'category']);
    }

    public function update(Concern $concern, array $data): Concern
    {
        $concern->fill(collect($data)->only([
            'engagement_id', 'stakeholder_id', 'location_id', 'grievance_category_id',
            'title', 'description', 'raised_by', 'raised_on', 'severity_hint', 'status',
            'response', 'owner_id',
        ])->all());
        $concern->save();

        return $concern->fresh();
    }

    /**
     * Build the pre-filled grievance payload from a concern. The caller passes
     * this to the intake engine, so escalation is one confirm step rather than
     * a re-keyed form.
     */
    public function grievanceDraftFrom(Concern $concern): array
    {
        if ($concern->grievance_id) {
            throw new DomainRuleException(
                "This concern was already escalated as {$concern->grievance->reference}.",
                'already_escalated'
            );
        }

        $stakeholder = $concern->stakeholder;

        return [
            'channel' => 'in_person',
            'received_at' => now(),
            'occurred_at' => $concern->raised_on,
            'confidentiality' => 'normal',
            'title' => $concern->title,
            'description' => $concern->description,
            'category_id' => $concern->grievance_category_id,
            'location_id' => $concern->location_id,
            'stakeholder_id' => $concern->stakeholder_id,
            'complainant_name' => $stakeholder?->name ?? $concern->raised_by,
            'complainant_phone' => $stakeholder?->phone,
            'complainant_type' => $stakeholder?->type,
            'complainant_language' => $stakeholder?->preferred_language,
            'source_concern_id' => $concern->id,
            'severity' => match ($concern->severity_hint) {
                'high' => 4,
                'medium' => 3,
                'low' => 2,
                default => null,
            },
        ];
    }

    public function markEscalated(Concern $concern, int $grievanceId, string $grievanceReference): Concern
    {
        return DB::transaction(function () use ($concern, $grievanceId, $grievanceReference) {
            $concern->forceFill([
                'grievance_id' => $grievanceId,
                'status' => 'escalated',
                'escalated_at' => now(),
                'escalated_by' => auth()->id(),
            ])->save();

            $this->audit->record(
                action: 'concern.escalated',
                entity: $concern,
                after: ['grievance' => $grievanceReference],
                summary: "Escalated to grievance {$grievanceReference}",
            );

            return $concern->fresh('grievance');
        });
    }
}
