<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Files live on a private disk. The raw storage path is NEVER sent to the
 * client; downloads go through a short-lived signed URL and are audited.
 */
class Attachment extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasFactory;

    public const AUDIT_NAME = 'attachment';

    protected $fillable = [
        'organisation_id', 'project_id', 'attachable_type', 'attachable_id',
        'client_uuid', 'kind', 'original_name', 'disk', 'path', 'mime_type',
        'size_bytes', 'checksum', 'caption', 'is_sensitive', 'scan_status',
        'scan_result', 'captured_at', 'synced_at', 'uploaded_by',
    ];

    protected $hidden = ['path', 'disk'];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'size_bytes' => 'integer',
            'captured_at' => 'datetime',
            'synced_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function attachable()
    {
        return $this->morphTo();
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
