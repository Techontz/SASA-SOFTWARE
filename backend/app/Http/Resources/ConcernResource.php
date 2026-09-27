<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConcernResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'description' => $this->description,
            'raised_by' => $this->raised_by,
            'raised_on' => $this->raised_on?->toDateString(),
            'severity_hint' => $this->severity_hint,
            'status' => $this->status,
            'response' => $this->response,

            'engagement' => $this->whenLoaded('engagement', fn () => $this->engagement ? [
                'id' => $this->engagement->id,
                'reference' => $this->engagement->reference,
                'topic' => $this->engagement->topic,
                'held_at' => $this->engagement->held_at?->toIso8601String(),
            ] : null),
            'stakeholder' => $this->whenLoaded('stakeholder', fn () => $this->stakeholder ? [
                'id' => $this->stakeholder->id,
                'reference' => $this->stakeholder->reference,
                'name' => $this->stakeholder->name,
            ] : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id, 'name' => $this->category->name,
            ] : null),
            'grievance' => $this->whenLoaded('grievance', fn () => $this->grievance ? [
                'id' => $this->grievance->id,
                'reference' => $this->grievance->reference,
                'status' => $this->grievance->status,
            ] : null),
            'grievance_id' => $this->grievance_id,
            'escalated_at' => $this->escalated_at?->toIso8601String(),

            'location' => [
                'id' => $this->location_id,
                'path' => $this->whenLoaded('location', fn () => $this->location?->path),
            ],
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null),
            'commitments' => CommitmentResource::collection($this->whenLoaded('commitments')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),

            'client_uuid' => $this->client_uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
