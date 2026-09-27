<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Last-write-wins per field is the default, but important fields are never
 * silently overwritten — they land here for a human to resolve, showing the
 * local value, the server value, who changed it, when and from which device.
 */
class SyncConflict extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'sync_operation_id', 'entity', 'entity_id',
        'entity_reference', 'conflicting_fields', 'device_id', 'raised_by', 'status',
        'resolution', 'resolved_values', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'conflicting_fields' => 'array',
            'resolved_values' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function operation()
    {
        return $this->belongsTo(SyncOperation::class, 'sync_operation_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
