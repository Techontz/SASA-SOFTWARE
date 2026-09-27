<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Nothing in SASA is hard-deleted. "Delete" means archive with an audit event.
 */
trait Archivable
{
    public function archive(?string $reason = null): void
    {
        $this->forceFill([
            'archived_at' => now(),
            'status' => $this->hasAttribute('status') ? 'archived' : $this->status,
        ])->save();
    }

    public function restore(): void
    {
        $this->forceFill(['archived_at' => null, 'status' => 'active'])->save();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->getTable().'.archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull($query->getModel()->getTable().'.archived_at');
    }
}
