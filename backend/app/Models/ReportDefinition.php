<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A report is a saved definition executed against live records. */
class ReportDefinition extends Model
{
    use Auditable, HasFactory;

    public const AUDIT_NAME = 'report_definition';

    protected $fillable = [
        'organisation_id', 'project_id', 'key', 'name', 'description', 'template',
        'sections', 'filters', 'default_period', 'formats', 'schedule', 'recipients',
        'is_system', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'filters' => 'array',
            'default_period' => 'array',
            'formats' => 'array',
            'recipients' => 'array',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }
}
