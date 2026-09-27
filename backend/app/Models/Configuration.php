<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Project rows override organisation rows, which override config/sasa.php.
 * Every change is audited — configuration is powerful enough to break
 * comparability between projects.
 */
class Configuration extends Model
{
    use Auditable, HasFactory;

    public const AUDIT_NAME = 'configuration';

    protected $fillable = ['organisation_id', 'project_id', 'key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }
}
