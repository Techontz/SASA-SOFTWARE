<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngagementPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'project_phase' => $this->project_phase,
            'purpose' => $this->purpose,
            'method' => $this->method,
            'target_date' => $this->target_date?->toDateString(),
            'window_start' => $this->window_start?->toDateString(),
            'window_end' => $this->window_end?->toDateString(),
            'status' => $this->status,
            'status_label' => ucfirst(str_replace('_', ' ', (string) $this->status)),
            'status_reason' => $this->status_reason,
            'priority' => $this->priority,
            'recurrence' => $this->recurrence,
            'recurrence_until' => $this->recurrence_until?->toDateString(),

            'stakeholder' => $this->whenLoaded('stakeholder', fn () => $this->stakeholder ? [
                'id' => $this->stakeholder->id,
                'reference' => $this->stakeholder->reference,
                'name' => $this->stakeholder->name,
            ] : null),
            'stakeholder_group' => $this->stakeholder_group,

            'location' => [
                'id' => $this->location_id,
                'path' => $this->whenLoaded('location', fn () => $this->location?->path),
                'text' => $this->location_text,
            ],

            'vulnerable_group_accommodation' => (bool) $this->vulnerable_group_accommodation,
            'accommodation_notes' => $this->accommodation_notes,
            'fpic_required' => (bool) $this->fpic_required,
            'fpic_notes' => $this->fpic_notes,
            'grievance_channel_available' => (bool) $this->grievance_channel_available,

            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null),
            'responsible_team' => $this->responsible_team,
            'budget_amount' => $this->budget_amount !== null ? (float) $this->budget_amount : null,
            'budget_currency' => $this->budget_currency,
            'resources_required' => $this->resources_required,

            'engagements' => EngagementResource::collection($this->whenLoaded('engagements')),
            'counts' => ['engagements' => $this->whenCounted('engagements')],

            'custom_fields' => $this->custom_fields,
            'client_uuid' => $this->client_uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
