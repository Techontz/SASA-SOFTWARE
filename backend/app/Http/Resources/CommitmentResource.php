<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommitmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'commitment_text' => $this->commitment_text,
            'source_type' => $this->source_type,
            'source_date' => $this->source_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'days_until_due' => $this->daysUntilDue(),
            'is_overdue' => $this->isOverdue(),
            'priority' => $this->priority,
            'risk_level' => $this->risk_level,
            'status' => $this->status,
            'status_label' => ucfirst(str_replace('_', ' ', (string) $this->status)),
            'completed_on' => $this->completed_on?->toDateString(),
            'evidence_notes' => $this->evidence_notes,
            'verification_status' => $this->verification_status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier?->name),
            'notes' => $this->notes,

            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null),
            'owner_team' => $this->owner_team,

            'engagement' => $this->whenLoaded('engagement', fn () => $this->engagement ? [
                'id' => $this->engagement->id,
                'reference' => $this->engagement->reference,
                'topic' => $this->engagement->topic,
                'held_at' => $this->engagement->held_at?->toIso8601String(),
            ] : null),
            'grievance' => $this->whenLoaded('grievance', fn () => $this->grievance ? [
                'id' => $this->grievance->id, 'reference' => $this->grievance->reference,
            ] : null),
            'concern_id' => $this->concern_id,
            'stakeholders' => $this->whenLoaded('stakeholders', fn () => $this->stakeholders->map(fn ($s) => [
                'id' => $s->id, 'reference' => $s->reference, 'name' => $s->name,
            ])),
            'location' => [
                'id' => $this->location_id,
                'path' => $this->whenLoaded('location', fn () => $this->location?->path),
            ],
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),

            'custom_fields' => $this->custom_fields,
            'client_uuid' => $this->client_uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
