<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use Auditable, HasFactory;

    public const AUDIT_NAME = 'role';

    protected $fillable = [
        'organisation_id', 'key', 'name', 'description', 'is_system', 'escalation_rank',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'escalation_rank' => 'integer'];
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function memberships()
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /** @return array<int,string> */
    public function permissionKeys(): array
    {
        return $this->relationLoaded('permissions')
            ? $this->permissions->pluck('key')->all()
            : $this->permissions()->pluck('key')->all();
    }
}
