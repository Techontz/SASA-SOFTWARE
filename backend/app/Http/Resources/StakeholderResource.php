<?php

namespace App\Http\Resources;

use App\Domain\Stakeholder\PriorityCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StakeholderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name,
            'alias' => $this->alias,
            'type' => $this->type,
            'type_label' => ucfirst(str_replace('_', ' ', (string) $this->type)),
            'sub_type' => $this->sub_type,
            'organisation_name' => $this->organisation_name,
            'position' => $this->position,

            'phone' => $this->phone,
            'alternate_phone' => $this->alternate_phone,
            'email' => $this->email,
            'preferred_language' => $this->preferred_language,
            'preferred_contact_method' => $this->preferred_contact_method,
            'physical_address' => $this->physical_address,
            'postal_address' => $this->postal_address,

            'location' => [
                'id' => $this->primary_location_id,
                'path' => $this->whenLoaded('primaryLocation', fn () => $this->primaryLocation?->path),
                'country' => $this->country,
                'region' => $this->region,
                'district' => $this->district,
                'ward' => $this->ward,
                'village' => $this->village,
                'display' => $this->displayLocation(),
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],

            'assessment' => [
                'influence' => $this->influence,
                'interest' => $this->interest,
                'power' => $this->power,
                'impact' => $this->impact,
                'score' => $this->priority_score,
                'calculated_priority' => $this->calculated_priority,
                'stored_priority' => $this->priority,
                'is_overridden' => (bool) $this->priority_overridden,
                'explanation' => $this->when($this->priority_score !== null, fn () => app(PriorityCalculator::class)->explain(
                    app(PriorityCalculator::class)->calculate(
                        $this->influence, $this->interest, $this->power, $this->impact,
                        $this->organisation_id, $this->project_id
                    )
                )),
                'override_reason' => $this->whenLoaded('currentAssessment', fn () => $this->currentAssessment?->override_reason),
            ],
            'engagement_strategy' => $this->engagement_strategy,
            'communication_frequency' => $this->communication_frequency,

            'concerns_expectations' => $this->concerns_expectations,
            'notes' => $this->notes,

            'is_vulnerable' => (bool) $this->is_vulnerable,
            'vulnerability_categories' => $this->vulnerability_categories ?? [],
            'is_indigenous_or_minority' => (bool) $this->is_indigenous_or_minority,
            'consent_status' => $this->consent_status,
            'consent_basis' => $this->consent_basis,
            'consent_date' => $this->consent_date?->toDateString(),
            'identification_source' => $this->identification_source,
            'identification_method' => $this->identification_method,

            'demographics' => $this->demographics,
            'custom_fields' => $this->custom_fields,

            'status' => $this->status,
            'review_date' => $this->review_date?->toDateString(),
            'review_due' => $this->review_date !== null && $this->review_date->isPast(),
            'last_engaged_on' => $this->last_engaged_on?->toDateString(),
            'merged_into_id' => $this->merged_into_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null),

            'client_uuid' => $this->client_uuid,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'counts' => [
                'engagements' => $this->whenCounted('engagements'),
                'concerns' => $this->whenCounted('concerns'),
                'grievances' => $this->whenCounted('grievances'),
                'commitments' => $this->whenCounted('commitments'),
                'attachments' => $this->whenCounted('attachments'),
            ],

            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'role' => $c->role,
                'phone' => $c->phone, 'email' => $c->email, 'is_primary' => $c->is_primary,
            ])),
            'assessments' => $this->whenLoaded('assessments', fn () => $this->assessments->map(fn ($a) => [
                'id' => $a->id,
                'score' => $a->score,
                'calculated_priority' => $a->calculated_priority,
                'stored_priority' => $a->stored_priority,
                'is_override' => $a->is_override,
                'previous_priority' => $a->previous_priority,
                'override_reason' => $a->override_reason,
                'weights' => $a->weights,
                'assessed_by' => $a->assessor?->name,
                'assessed_at' => $a->assessed_at?->toIso8601String(),
            ])),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
