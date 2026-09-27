<?php

namespace App\Http\Resources;

use App\Domain\Grievance\GrievanceVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Field-level confidentiality happens HERE, not in the browser.
 *
 * If the viewer is not in the handling group, complainant identity and precise
 * location are not included in the payload at all — there is nothing for a
 * developer console, a proxy log or a cached response to reveal.
 */
class GrievanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GrievanceVisibility $visibility */
        $visibility = app(GrievanceVisibility::class);
        $canSeeIdentity = $visibility->canSeeIdentity($this->resource);

        if ($canSeeIdentity && $this->isConfidential()) {
            $visibility->recordSensitiveView($this->resource);
        }

        $payload = [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'description' => $this->description,
            'desired_resolution' => $this->desired_resolution,

            'channel' => $this->channel,
            'channel_label' => ucfirst(str_replace('_', ' ', $this->channel)),
            'received_at' => $this->received_at?->toIso8601String(),
            'occurred_at' => $this->occurred_at?->toIso8601String(),

            'confidentiality' => $this->confidentiality,
            'is_restricted' => (bool) $this->is_restricted,
            'is_anonymous' => $this->isAnonymous(),
            'identity_visible' => $canSeeIdentity,
            'identity_withheld_reason' => $this->identityWithheldReason($canSeeIdentity),

            'status' => $this->status,
            'status_label' => ucfirst(str_replace('_', ' ', $this->status)),
            'severity' => $this->severity,
            'severity_label' => $this->severity ? $this->severityLabel() : null,
            'classification_confirmed' => (bool) $this->classification_confirmed,
            'has_ai_suggestions' => (bool) $this->has_ai_suggestions,

            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'is_restricted' => $this->category->is_restricted,
            ]),
            'subcategory' => $this->whenLoaded('subcategory', fn () => [
                'id' => $this->subcategory->id,
                'name' => $this->subcategory->name,
            ]),

            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'path' => $this->location->path,
                'level' => $this->location->level,
            ] : null),
            'location_text' => $this->location_text,

            'complainant_type' => $this->complainant_type,
            'complainant_language' => $this->complainant_language,
            'preferred_contact_method' => $this->preferred_contact_method,

            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'assigned_team' => $this->assigned_team,
            'assigned_at' => $this->assigned_at?->toIso8601String(),

            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'acknowledgement_method' => $this->acknowledgement_method,
            'acknowledgement_possible' => (bool) $this->acknowledgement_possible,
            'acknowledgement_not_possible_reason' => $this->acknowledgement_not_possible_reason,

            'investigation_started_at' => $this->investigation_started_at?->toIso8601String(),
            'investigation_summary' => $this->investigation_summary,
            'investigation_findings' => $this->investigation_findings,
            'investigation_completed_at' => $this->investigation_completed_at?->toIso8601String(),

            'corrective_action' => $this->corrective_action,
            'corrective_action_due' => $this->corrective_action_due?->toDateString(),

            'resolution_summary' => $this->resolution_summary,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'complainant_response' => $this->complainant_response,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closure_notes' => $this->closure_notes,

            'resolution_cycle' => $this->resolution_cycle,
            'reopen_count' => $this->reopen_count,
            'last_reopened_at' => $this->last_reopened_at?->toIso8601String(),
            'escalation_level' => $this->escalation_level,

            'sla' => [
                'acknowledgement_due_at' => $this->acknowledgement_due_at?->toIso8601String(),
                'acknowledgement_state' => $this->acknowledgement_sla_state,
                'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
                'resolution_state' => $this->resolution_sla_state,
            ],

            'days_open' => $this->daysOpen(),
            'demographics' => $this->demographics,
            'custom_fields' => $this->custom_fields,

            'source_concern_id' => $this->source_concern_id,
            'voice_call_id' => $this->voice_call_id,
            'client_uuid' => $this->client_uuid,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'counts' => [
                'follow_ups' => $this->whenCounted('followUps'),
                'attachments' => $this->whenCounted('attachments'),
                'commitments' => $this->whenCounted('commitments'),
                'communications' => $this->whenCounted('communications'),
            ],

            'follow_ups' => GrievanceFollowUpResource::collection($this->whenLoaded('followUps')),
            'assignments' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn ($a) => [
                'id' => $a->id,
                'assigned_to' => $a->assignee?->name,
                'assigned_team' => $a->assigned_team,
                'assigned_by' => $a->assigner?->name,
                'assigned_at' => $a->assigned_at?->toIso8601String(),
                'unassigned_at' => $a->unassigned_at?->toIso8601String(),
                'reason' => $a->reason,
                'is_current' => $a->is_current,
            ])),
            'cycles' => $this->whenLoaded('cycles', fn () => $this->cycles->map(fn ($c) => [
                'cycle_number' => $c->cycle_number,
                'opened_at' => $c->opened_at?->toIso8601String(),
                'reopen_reason' => $c->reopen_reason,
                'resolution_summary' => $c->resolution_summary,
                'resolved_at' => $c->resolved_at?->toIso8601String(),
                'complainant_response' => $c->complainant_response,
                'closed_at' => $c->closed_at?->toIso8601String(),
            ])),
            'escalations' => $this->whenLoaded('escalations', fn () => $this->escalations->map(fn ($e) => [
                'id' => $e->id,
                'from_level' => $e->from_level,
                'to_level' => $e->to_level,
                'trigger' => $e->trigger,
                'reason' => $e->reason,
                'escalated_to' => $e->escalatedTo?->name,
                'escalated_at' => $e->escalated_at?->toIso8601String(),
            ])),
            'communications' => $this->whenLoaded('communications', fn () => $this->communications->map(fn ($c) => [
                'id' => $c->id,
                'direction' => $c->direction,
                'channel' => $c->channel,
                'template_key' => $c->template_key,
                'subject' => $c->subject,
                'body' => $c->body,
                'status' => $c->status,
                'sent_at' => $c->sent_at?->toIso8601String(),
                'sent_by' => $c->sender?->name,
            ])),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'ai_suggestions' => $this->whenLoaded('aiSuggestions', fn () => $this->aiSuggestions->map(fn ($s) => [
                'id' => $s->id,
                'kind' => $s->kind,
                'suggestion' => $s->suggestion,
                'confidence' => $s->confidence,
                'provider' => $s->provider,
                'status' => $s->status,
                'reviewed_by' => $s->reviewer?->name,
                'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            ])),
        ];

        // ------------------------------------------------------------------
        // Sensitive fields. Added ONLY for an authorised handler; for everyone
        // else these keys do not exist in the response.
        // ------------------------------------------------------------------
        if ($canSeeIdentity) {
            $payload['complainant'] = [
                'name' => $this->complainant_name,
                'phone' => $this->complainant_phone,
                'email' => $this->complainant_email,
                'address' => $this->complainant_address,
            ];
            $payload['precise_location'] = $this->precise_location;
            $payload['coordinates'] = $this->latitude !== null
                ? ['latitude' => (float) $this->latitude, 'longitude' => (float) $this->longitude]
                : null;
            $payload['stakeholder'] = $this->whenLoaded('stakeholder', fn () => $this->stakeholder ? [
                'id' => $this->stakeholder->id,
                'reference' => $this->stakeholder->reference,
                'name' => $this->stakeholder->name,
            ] : null);
        }

        return $payload;
    }

    private function identityWithheldReason(bool $canSeeIdentity): ?string
    {
        if ($canSeeIdentity) {
            return null;
        }

        if ($this->isAnonymous()) {
            return 'This case was submitted anonymously. No identity was ever recorded.';
        }

        if ($this->isConfidential()) {
            return 'This case is confidential. Complainant details are only released to the handling group.';
        }

        return 'You do not have permission to see complainant details.';
    }
}
