<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The engine holds the mechanism; the client sets the numbers.
 * Match specificity: category+severity (4) > category (2) > severity (1) > base (0).
 */
class SlaPolicy extends Model
{
    use Auditable, HasFactory;

    public const AUDIT_NAME = 'sla_policy';

    protected $fillable = [
        'organisation_id', 'project_id', 'country', 'category_id', 'severity',
        'clock', 'unit', 'target_value', 'working_calendar_id', 'reminder_thresholds',
        'escalate_to_role_id', 'is_active', 'specificity', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reminder_thresholds' => 'array',
            'is_active' => 'boolean',
            'target_value' => 'integer',
            'severity' => 'integer',
            'specificity' => 'integer',
        ];
    }

    public function category()
    {
        return $this->belongsTo(GrievanceCategory::class, 'category_id');
    }

    public function calendar()
    {
        return $this->belongsTo(WorkingCalendar::class, 'working_calendar_id');
    }

    public function escalateToRole()
    {
        return $this->belongsTo(Role::class, 'escalate_to_role_id');
    }

    public static function computeSpecificity(?int $categoryId, ?int $severity, ?int $projectId): int
    {
        return ($categoryId ? 4 : 0) + ($severity ? 2 : 0) + ($projectId ? 1 : 0);
    }
}
