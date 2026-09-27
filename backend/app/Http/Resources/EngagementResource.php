<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngagementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'topic' => $this->topic,
            'project_phase' => $this->project_phase,
            'held_at' => $this->held_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'method' => $this->method,
            'venue' => $this->venue,
            'organised_by' => $this->organised_by,
            'aim' => $this->aim,
            'discussion_points' => $this->discussion_points,
            'outcomes' => $this->outcomes,
            'status' => $this->status,

            'location' => [
                'id' => $this->location_id,
                'path' => $this->whenLoaded('location', fn () => $this->location?->path),
                'text' => $this->location_text,
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],

            'attendance' => [
                'total' => $this->attendance_total,
                'female' => $this->attendance_female,
                'male' => $this->attendance_male,
                'youth' => $this->attendance_youth,
                'elderly' => $this->attendance_elderly,
                'disability' => $this->attendance_disability,
                'vulnerable' => $this->attendance_vulnerable,
                'breakdown' => $this->attendance_breakdown,
            ],
            'vulnerable_groups_present' => (bool) $this->vulnerable_groups_present,
            'vulnerable_groups' => $this->vulnerable_groups ?? [],

            'planned_vs_actual' => $this->planned_vs_actual,
            'planned_vs_actual_label' => ucfirst(str_replace('_', ' ', (string) $this->planned_vs_actual)),
            'variance_days' => $this->variance_days,

            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? [
                'id' => $this->plan->id,
                'reference' => $this->plan->reference,
                'title' => $this->plan->title,
                'target_date' => $this->plan->target_date?->toDateString(),
                'status' => $this->plan->status,
            ] : null),

            'facilitator' => $this->whenLoaded('facilitator', fn () => $this->facilitator ? [
                'id' => $this->facilitator->id, 'name' => $this->facilitator->name,
            ] : null),

            'stakeholders' => $this->whenLoaded('stakeholders', fn () => $this->stakeholders->map(fn ($s) => [
                'id' => $s->id, 'reference' => $s->reference, 'name' => $s->name, 'type' => $s->type,
            ])),
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'category' => $p->category,
                'organisation_name' => $p->organisation_name, 'position' => $p->position,
                'is_vulnerable' => $p->is_vulnerable, 'demographics' => $p->demographics,
                'signed_attendance' => $p->signed_attendance, 'stakeholder_id' => $p->stakeholder_id,
            ])),
            'concerns' => ConcernResource::collection($this->whenLoaded('concerns')),
            'commitments' => CommitmentResource::collection($this->whenLoaded('commitments')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),

            'counts' => [
                'concerns' => $this->whenCounted('concerns'),
                'commitments' => $this->whenCounted('commitments'),
                'participants' => $this->whenCounted('participants'),
                'attachments' => $this->whenCounted('attachments'),
            ],

            'custom_fields' => $this->custom_fields,
            'client_uuid' => $this->client_uuid,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
