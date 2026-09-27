<?php

namespace App\Domain\Tenancy;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\DomainRuleException;

/**
 * The single source of tenant truth for a request.
 *
 * Tenant scope is derived from the authenticated user's memberships — NEVER
 * from an organisation_id or project_id supplied by the client. A client may
 * ask for a project by id; it may not assert that it belongs to one.
 */
final class TenantContext
{
    private ?User $user = null;

    private ?Project $project = null;

    private ?ProjectMembership $membership = null;

    /** @var array<int,string>|null */
    private ?array $permissions = null;

    public function setUser(?User $user): void
    {
        $this->user = $user;
        $this->permissions = null;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function requireUser(): User
    {
        return $this->user ?? throw new DomainRuleException(
            'You need to sign in to continue.', 'unauthenticated', 401
        );
    }

    public function setProject(Project $project, ?ProjectMembership $membership): void
    {
        $this->project = $project;
        $this->membership = $membership;
        $this->permissions = null;
    }

    public function project(): ?Project
    {
        return $this->project;
    }

    public function requireProject(): Project
    {
        return $this->project ?? throw new DomainRuleException(
            'Choose a project before continuing.', 'project_required', 400
        );
    }

    public function projectId(): ?int
    {
        return $this->project?->id;
    }

    public function organisationId(): ?int
    {
        return $this->project?->organisation_id ?? $this->user?->organisation_id;
    }

    public function membership(): ?ProjectMembership
    {
        return $this->membership;
    }

    public function roleKey(): ?string
    {
        return $this->membership?->role?->key;
    }

    /**
     * Permission keys granted to this user on the CURRENT project.
     * A system administrator carries every permission everywhere.
     *
     * @return array<int,string>
     */
    public function permissions(): array
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }

        if ($this->user?->is_system_admin) {
            return $this->permissions = ['*'];
        }

        return $this->permissions = $this->membership?->role?->permissionKeys() ?? [];
    }

    public function can(string $permission): bool
    {
        $permissions = $this->permissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handling groups gate restricted grievance categories (SEA/SH,
     * Retaliation, Ethics & Compliance) regardless of project role.
     *
     * @return array<int,string>
     */
    public function handlingGroups(): array
    {
        if ($this->user?->is_system_admin) {
            return ['*'];
        }

        return $this->membership?->handling_groups ?? [];
    }

    public function inHandlingGroup(?array $groups): bool
    {
        if ($groups === null || $groups === []) {
            return true;
        }

        $mine = $this->handlingGroups();

        if (in_array('*', $mine, true)) {
            return true;
        }

        return array_intersect($groups, $mine) !== [];
    }

    public function isSystemAdmin(): bool
    {
        return (bool) $this->user?->is_system_admin;
    }
}
