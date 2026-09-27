<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Assignment HISTORY. Who held the case, when, and why it moved. */
class Assignment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'grievance_id', 'assigned_to_id',
        'assigned_team', 'assigned_by', 'assigned_at', 'unassigned_at', 'reason', 'is_current',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    public function grievance()
    {
        return $this->belongsTo(Grievance::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
