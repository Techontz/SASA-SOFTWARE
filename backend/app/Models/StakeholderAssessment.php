<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Versioned priority assessment. Every recalculation or override writes a new
 * row, so "the calculated value next to the stored value" holds over time and
 * systematic overrides are countable — a systematic override signals a wrong
 * weighting, not a wrong record.
 */
class StakeholderAssessment extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'stakeholder_id', 'influence', 'interest',
        'power', 'impact', 'weights', 'score', 'calculated_priority', 'stored_priority',
        'is_override', 'previous_priority', 'override_reason', 'is_current',
        'assessed_by', 'assessed_at',
    ];

    protected function casts(): array
    {
        return [
            'weights' => 'array',
            'is_override' => 'boolean',
            'is_current' => 'boolean',
            'assessed_at' => 'datetime',
            'score' => 'integer',
        ];
    }

    public function stakeholder()
    {
        return $this->belongsTo(Stakeholder::class);
    }

    public function assessor()
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
