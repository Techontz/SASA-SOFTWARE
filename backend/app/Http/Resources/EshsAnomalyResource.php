<?php

namespace App\Http\Resources;

use App\Domain\Eshs\EshsConfiguration;
use App\Domain\Tenancy\TenantContext;
use App\Models\EshsAnomaly;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EshsAnomaly
 *
 * The action owner's e-mail and phone are personal contact details. They are
 * added only for people who act on anomalies (raise, respond or verify); a
 * read-only viewer such as an auditor or executive does not receive the keys.
 */
class EshsAnomalyResource extends JsonResource
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $history = null;

    public function withHistory(array $history): static
    {
        $this->history = $history;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $context = app(TenantContext::class);
        $project = $context->project() ?? $this->project;
        $config = app(EshsConfiguration::class);
        $user = $request->user();
        $stage = $this->stage();

        $data = [
            'id' => $this->id,
            'reference' => $this->reference,
            'raised_on' => $this->raised_on?->toDateString(),
            'location' => [
                'id' => $this->location_id,
                'path' => $this->whenLoaded('location', fn () => $this->location?->path ?? $this->location?->name),
            ],
            'contractor' => $this->contractor,
            'subcontractor' => $this->subcontractor,
            'category' => $this->category,
            'category_label' => $config->categoryLabel($project, $this->category),
            'subcategory' => $this->subcategory,
            'description' => $this->description,
            'anomaly_type' => $this->anomaly_type,
            'risk_rating' => $this->risk_rating,
            'risk_label' => $config->riskRatings($project)[$this->risk_rating] ?? ucfirst((string) $this->risk_rating),
            'root_cause_group' => $this->root_cause_group,
            'root_cause' => $this->root_cause,

            'contractor_action' => $this->contractor_action,
            'action_owner_name' => $this->action_owner_name,
            'target_date' => $this->target_date?->toDateString(),
            'days_until_target' => $this->daysUntilTarget(),
            'is_overdue' => $this->isOverdue(),
            'progress_notes' => $this->progress_notes,

            'status' => $this->status,
            'status_label' => match ($this->status) {
                'in_progress' => 'In progress',
                default => ucfirst((string) $this->status),
            },
            'stage' => $stage,
            'stage_label' => match ($stage) {
                'awaiting_verification' => 'Awaiting verification',
                'in_progress' => 'In progress',
                default => ucfirst($stage),
            },
            'evidence_submitted_at' => $this->evidence_submitted_at?->toIso8601String(),
            'closure_notes' => $this->closure_notes,
            'returned_at' => $this->returned_at?->toIso8601String(),
            'return_reason' => $this->return_reason,
            'closed_on' => $this->closed_on?->toDateString(),
            'closed_by' => $this->whenLoaded('closer', fn () => $this->closer ? ['id' => $this->closer->id, 'name' => $this->closer->name] : null),
            'verification_notes' => $this->verification_notes,
            'raised_by' => $this->whenLoaded('raiser', fn () => $this->raiser ? ['id' => $this->raiser->id, 'name' => $this->raiser->name] : null),

            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($context->canAny('eshs.raise', 'eshs.respond', 'eshs.verify')) {
            $data['action_owner_email'] = $this->action_owner_email;
            $data['action_owner_phone'] = $this->action_owner_phone;
        }

        if ($user) {
            $data['can'] = [
                'edit' => $user->can('edit', $this->resource),
                'upload' => $user->can('update', $this->resource),
                'respond' => $user->can('respond', $this->resource),
                'verify' => $user->can('verify', $this->resource),
                'archive' => $user->can('archive', $this->resource),
            ];
        }

        if ($this->history !== null) {
            $data['history'] = $this->history;
        }

        return $data;
    }
}
