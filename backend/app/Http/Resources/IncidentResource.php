<?php

namespace App\Http\Resources;

use App\Domain\Incident\IncidentOptions;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The reviewer's internal note is for incident managers only: for anyone else
 * the key is absent from the response, not null.
 */
class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $options = app(IncidentOptions::class);
        $context = app(TenantContext::class);
        $canManage = $context->can('incident.manage');
        $user = $context->user();

        $data = [
            'id' => $this->id,
            'reference' => $this->reference,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'company' => $this->company,
            'location' => [
                'id' => $this->location_id,
                'path' => $this->whenLoaded('location', fn () => $this->location?->path),
            ],
            'site_detail' => $this->site_detail,
            'category' => $this->category,
            'category_label' => $options->categoryLabel($this->category),
            'incident_type' => $this->incident_type,
            'incident_type_label' => $options->typeLabel($this->incident_type),
            'actual_consequence' => $this->actual_consequence,
            'actual_consequence_label' => $options->levelLabel($this->actual_consequence),
            'potential_consequence' => $this->potential_consequence,
            'potential_consequence_label' => $options->levelLabel($this->potential_consequence),
            'is_serious' => $this->resource->isSerious(),
            'summary' => $this->summary,
            'reported_by' => $this->whenLoaded('reporter', fn () => $this->reporter ? ['id' => $this->reporter->id, 'name' => $this->reporter->name] : null),
            'contractor_manager' => $this->contractor_manager,
            'resident_engineer' => $this->resident_engineer,
            'status' => $this->status,
            'status_label' => ucfirst(str_replace('_', ' ', (string) $this->status)),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->whenLoaded('closer', fn () => $this->closer?->name),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'client_uuid' => $this->client_uuid,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'permissions' => [
                'can_manage' => $canManage && $this->archived_at === null,
                'can_add_evidence' => $user !== null && $user->can('update', $this->resource),
            ],
        ];

        if ($canManage) {
            $data['review_note'] = $this->review_note;
        }

        return $data;
    }
}
