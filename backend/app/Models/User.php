<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Archivable, Auditable, HasApiTokens, HasFactory, Notifiable;

    public const AUDIT_NAME = 'user';

    protected $fillable = [
        'organisation_id', 'name', 'email', 'phone', 'password', 'job_title',
        'locale', 'avatar_path', 'is_system_admin', 'status', 'notification_preferences',
        'mfa_enabled',
    ];

    protected $hidden = [
        'password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'archived_at' => 'datetime',
            'password' => 'hashed',
            'is_system_admin' => 'boolean',
            'mfa_enabled' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'notification_preferences' => 'array',
        ];
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function memberships()
    {
        return $this->hasMany(ProjectMembership::class);
    }

    public function activeMemberships()
    {
        return $this->memberships()->where('status', 'active')->with(['project', 'role']);
    }

    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_memberships')
            ->withPivot(['role_id', 'status', 'handling_groups', 'is_default'])
            ->withTimestamps();
    }

    /** The project the app opens on, if the user has one. */
    public function getDefaultProjectIdAttribute(): ?int
    {
        return $this->memberships()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('project_id');
    }

    public function membershipFor(int $projectId): ?ProjectMembership
    {
        return $this->memberships()
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->first();
    }

    public function roleOn(int $projectId): ?Role
    {
        return $this->membershipFor($projectId)?->role;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function tokenTtlMinutes(): int
    {
        return $this->is_system_admin
            ? (int) config('sasa.auth.admin_token_ttl_minutes')
            : (int) config('sasa.auth.token_ttl_minutes');
    }
}
