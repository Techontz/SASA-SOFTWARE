<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The server-side ledger of client operations. The client's operation UUID is
 * unique here, so a retried batch replays without creating duplicates.
 */
class SyncOperation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'user_id', 'operation_uuid', 'device_id',
        'entity', 'operation', 'entity_uuid', 'payload', 'status', 'server_entity_id',
        'server_reference', 'error', 'validation_errors', 'client_retry_count',
        'client_created_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'validation_errors' => 'array',
            'client_created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function conflicts()
    {
        return $this->hasMany(SyncConflict::class);
    }
}
