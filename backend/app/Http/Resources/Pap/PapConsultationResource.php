<?php

namespace App\Http\Resources\Pap;

use App\Domain\Pap\PapOptions;
use App\Domain\Tenancy\TenantContext;
use App\Models\Pap\PapConsultation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PapConsultation */
class PapConsultationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $project = app(TenantContext::class)->project() ?? $this->project;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'consultation_date' => $this->consultation_date?->toDateString(),
            'location' => [
                'id' => $this->location_id,
                'path' => $this->whenLoaded('location', fn () => $this->location?->path),
            ],
            'village' => $this->village,
            'meeting_type' => $this->meeting_type,
            'meeting_type_label' => $project ? app(PapOptions::class)->label($project, 'meeting_types', $this->meeting_type) : $this->meeting_type,
            'topic_discussed' => $this->topic_discussed,
            'facilitator' => $this->facilitator,
            'number_male' => (int) $this->number_male,
            'number_female' => (int) $this->number_female,
            'number_vulnerable' => (int) $this->number_vulnerable,
            'total_attendance' => $this->totalAttendance(),
            'attendance_sheet_reference' => $this->attendance_sheet_reference,
            'key_concerns_raised' => $this->key_concerns_raised,
            'actions_agreed' => $this->actions_agreed,
            'status' => $this->status,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
