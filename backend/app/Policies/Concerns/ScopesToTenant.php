<?php

namespace App\Policies\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every policy answers two questions: does this user hold the permission on
 * THIS project, and does the record actually belong to that project.
 */
trait ScopesToTenant
{
    protected function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function allows(User $user, string $permission, ?Model $record = null): bool
    {
        $context = $this->context();

        if ($context->user()?->id !== $user->id) {
            $context->setUser($user);
        }

        // A record belonging to another project does not exist as far as this
        // request is concerned. Returning 403 here would confirm that the id
        // is real on some other tenant; 404 confirms nothing.
        if ($record !== null && ! $this->belongsToCurrentProject($record)) {
            throw new NotFoundHttpException;
        }

        return $context->can($permission);
    }

    protected function belongsToCurrentProject(Model $record): bool
    {
        $projectId = $this->context()->projectId();

        if ($projectId === null) {
            return $this->context()->isSystemAdmin();
        }

        return (int) $record->getAttribute('project_id') === (int) $projectId;
    }

    /** An archived record is read-only. */
    protected function isEditable(Model $record): bool
    {
        return $record->getAttribute('archived_at') === null;
    }
}
