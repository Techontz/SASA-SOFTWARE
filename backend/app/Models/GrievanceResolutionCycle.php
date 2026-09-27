<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Reopening keeps the original case ID and opens a new cycle. Without this,
 * dissatisfaction produces duplicate cases and the resolution statistics
 * flatter the project.
 */
class GrievanceResolutionCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'grievance_id', 'cycle_number', 'opened_at', 'reopen_reason',
        'resolution_summary', 'resolved_at', 'resolved_by', 'complainant_response', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'cycle_number' => 'integer',
        ];
    }

    public function grievance()
    {
        return $this->belongsTo(Grievance::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
