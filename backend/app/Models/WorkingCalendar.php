<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** "7 working days" is meaningless without one of these. */
class WorkingCalendar extends Model
{
    use Auditable, HasFactory;

    public const AUDIT_NAME = 'working_calendar';

    protected $fillable = [
        'organisation_id', 'project_id', 'name', 'country', 'timezone',
        'working_days', 'work_start', 'work_end', 'is_default',
    ];

    protected function casts(): array
    {
        return ['working_days' => 'array', 'is_default' => 'boolean'];
    }

    public function holidays()
    {
        return $this->hasMany(Holiday::class);
    }
}
