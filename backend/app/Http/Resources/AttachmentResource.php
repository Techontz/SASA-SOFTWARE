<?php

namespace App\Http\Resources;

use App\Domain\Attachment\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The storage path never appears here — only a short-lived signed URL. */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'size_label' => $this->humanSize(),
            'caption' => $this->caption,
            'is_sensitive' => (bool) $this->is_sensitive,
            'is_image' => $this->isImage(),
            'scan_status' => $this->scan_status,
            'download_url' => app(AttachmentService::class)->temporaryUrl($this->resource),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'captured_at' => $this->captured_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
