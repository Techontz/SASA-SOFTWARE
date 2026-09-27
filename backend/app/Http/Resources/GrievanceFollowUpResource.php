<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrievanceFollowUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'body' => $this->body,
            'is_sensitive' => (bool) $this->is_sensitive,
            'occurred_on' => $this->occurred_on?->toDateString(),
            'resolution_cycle' => $this->resolution_cycle,
            'author' => $this->whenLoaded('author', fn () => $this->author?->name),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
