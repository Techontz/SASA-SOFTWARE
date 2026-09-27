<?php

namespace App\Models\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organisation;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Defence in depth for tenant isolation.
 *
 * Every operational model carries organisation_id + project_id. When a request
 * has resolved a project, queries are scoped to it automatically — so a
 * forgotten `where('project_id', ...)` in a controller cannot leak another
 * project's records. Background jobs run without a project context and see
 * everything, which is what a scheduler needs.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);

            if (empty($model->project_id) && $context->projectId()) {
                $model->project_id = $context->projectId();
            }

            if (empty($model->organisation_id)) {
                $model->organisation_id = $model->project?->organisation_id
                    ?? $context->organisationId();
            }
        });
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where($query->getModel()->getTable().'.project_id', $projectId);
    }

    /** Escape hatch for schedulers, sync workers and cross-project reporting. */
    public function scopeAcrossTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
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

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $projectId = app(TenantContext::class)->projectId();

        if ($projectId !== null) {
            $builder->where($model->getTable().'.project_id', $projectId);
        }
    }
}
