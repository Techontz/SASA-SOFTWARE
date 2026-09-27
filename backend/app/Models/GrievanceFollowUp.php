<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class GrievanceFollowUp extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const AUDIT_NAME = 'grievance_follow_up';

    protected $fillable = [
        'organisation_id', 'project_id', 'grievance_id', 'type', 'body',
        'is_sensitive', 'occurred_on', 'resolution_cycle', 'created_by',
        'captured_at', 'synced_at', 'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'occurred_on' => 'date',
            'captured_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function grievance()
    {
        return $this->belongsTo(Grievance::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
