<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExportLog extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'user_id', 'entity', 'format',
        'filters', 'row_count', 'identity_stripped', 'ip_address',
    ];

    protected function casts(): array
    {
        return ['filters' => 'array', 'identity_stripped' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
