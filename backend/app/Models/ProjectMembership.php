<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's role on ONE project. The same person may be a grievance officer on
 * one project and read-only on another.
 */
class ProjectMembership extends Model
{
    use Auditable, HasFactory;

    public const AUDIT_NAME = 'membership';

    protected $fillable = [
        'organisation_id', 'project_id', 'user_id', 'role_id',
        'handling_groups', 'is_default', 'status', 'joined_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'handling_groups' => 'array',
            'is_default' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
