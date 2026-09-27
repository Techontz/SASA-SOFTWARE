<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A generated artefact, retained with its parameters, generator and timestamp,
 * so a number in a past report can always be explained.
 */
class Report extends Model
{
    use BelongsToTenant, HasFactory, HasReference;

    public const REFERENCE_KEY = 'report';

    protected $fillable = [
        'organisation_id', 'project_id', 'report_definition_id', 'name', 'template',
        'format', 'filters', 'period_start', 'period_end', 'status', 'path',
        'size_bytes', 'metrics', 'failure_reason', 'generated_by', 'generated_at',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'metrics' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_at' => 'datetime',
        ];
    }

    public function definition()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    public function generator()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
