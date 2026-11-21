<?php

namespace App\Http\Resources\Enquiry;

use App\Models\Enquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The full enquiry for whoever holds enquiry.manage. The IP hash is never returned. */
class EnquiryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'reference' => $this->reference,
            'type' => $this->type,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'organization' => $this->organization,
            'sector' => $this->sector,
            'phone' => $this->phone,
            'modules' => $this->modules ?? [],
            // Quote requests only: the qualifying answers. Null for other types.
            'details' => $this->details
                ? array_merge(array_fill_keys(Enquiry::DETAIL_KEYS, null), array_intersect_key($this->details, array_flip(Enquiry::DETAIL_KEYS)))
                : null,
            'message' => $this->message,
            'status' => $this->status,
            'admin_notes' => $this->admin_notes,
            'handled_by' => $this->handler ? ['id' => $this->handler->id, 'name' => $this->handler->name] : null,
            'handled_at' => $this->handled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // The latest demo workspace given in answer to this enquiry, if any.
            'demo' => $this->latestDemo ? [
                'public_id' => $this->latestDemo->public_id,
                'status' => $this->latestDemo->effectiveStatus(),
                'ends_at' => $this->latestDemo->ends_at?->toIso8601String(),
            ] : null,
        ];
    }
}
