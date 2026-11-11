<?php

namespace App\Http\Resources\Pap;

use App\Domain\Pap\PapOptions;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\AttachmentResource;
use App\Models\Pap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Pap
 *
 * National ID is added ONLY inside `if ($canSeeSensitive)`. Without
 * pap.view_sensitive the key is absent — not null, not masked.
 */
class PapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $project = app(TenantContext::class)->project() ?? $this->project;
        $options = app(PapOptions::class);
        $label = fn (string $list, ?string $value) => $project ? $options->label($project, $list, $value) : $value;

        $data = [
            'id' => $this->id,
            'reference' => $this->reference,
            'household_id' => $this->household_id,
            'project_name' => $project?->name,
            'full_name' => $this->full_name,
            'gender' => $this->gender,
            'gender_label' => $label('genders', $this->gender),
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'stated_age' => $this->stated_age,
            'age' => $this->age(),
            'age_is_derived' => $this->date_of_birth !== null,
            'marital_status' => $this->marital_status,
            'marital_status_label' => $label('marital_statuses', $this->marital_status),
            'phone' => $this->phone,
            'alternative_contact' => $this->alternative_contact,
            'physical_address' => $this->physical_address,
            'location' => [
                'id' => $this->location_id,
                'path' => $this->whenLoaded('location', fn () => $this->location?->path),
                'region' => $this->region,
                'district' => $this->district,
                'ward' => $this->ward,
                'village' => $this->village,
                'kitongoji' => $this->kitongoji,
                'display' => $this->displayLocation(),
            ],
            'gps' => [
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],
            'next_of_kin' => $this->next_of_kin,
            'next_of_kin_contact' => $this->next_of_kin_contact,
            'vulnerability_category' => $this->vulnerability_category,
            'vulnerability_label' => $label('vulnerability_categories', $this->vulnerability_category),
            'is_vulnerable' => $this->isVulnerable(),
            'remarks' => $this->remarks,
            'status' => $this->status,
            'stakeholder' => $this->stakeholder_id ? [
                'id' => $this->stakeholder_id,
                'reference' => $this->whenLoaded('stakeholder', fn () => $this->stakeholder?->reference),
            ] : null,
            'photo' => $this->whenLoaded('attachments', function () {
                $photo = $this->attachments->where('kind', 'photo')->sortByDesc('id')->first();

                return $photo ? new AttachmentResource($photo) : null;
            }),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'money' => $this->when(array_key_exists('amount_paid_total', $this->resource->getAttributes()), fn () => [
                'amount_paid' => (float) ($this->resource->getAttribute('amount_paid_total') ?? 0),
                'outstanding' => (float) ($this->resource->getAttribute('outstanding_total') ?? 0),
            ]),
            'client_uuid' => $this->client_uuid,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        $canSeeSensitive = PapSensitivity::canSee();

        if ($canSeeSensitive) {
            $data['national_id'] = $this->national_id;
        }

        $data['sensitive_withheld'] = ! $canSeeSensitive;

        return $data;
    }
}
