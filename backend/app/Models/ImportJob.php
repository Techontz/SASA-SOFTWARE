<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'entity', 'original_name', 'path', 'headers',
        'mapping', 'options', 'status', 'rows_total', 'rows_valid', 'rows_invalid',
        'rows_committed', 'last_committed_row', 'errors', 'preview', 'committed_at', 'created_by',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'options' => 'array',
            'errors' => 'array',
            'preview' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
