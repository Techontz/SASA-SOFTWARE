<?php

namespace App\Domain\Demo;

use App\Models\Demo;
use Illuminate\Support\Collection;

/**
 * Read-only questions about demo workspaces, cheap enough to ask on every
 * project-scoped request.
 */
final class DemoAccess
{
    public const ENDED_MESSAGE = 'This SASA demo has ended. Contact the SASA team to continue.';

    /** The demo that currently shuts this project, if any (expired or ended, not converted). */
    public function blockingFor(int $projectId): ?Demo
    {
        $demo = $this->latestFor($projectId);

        return $demo && $demo->isBlocked() ? $demo : null;
    }

    public function latestFor(int $projectId): ?Demo
    {
        return Demo::query()
            ->current()
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The latest demo of each of these projects, keyed by project id. A
     * converted demo is no longer a demo, so it is left out.
     *
     * @param  array<int,int>  $projectIds
     * @return Collection<int,Demo>
     */
    public function forProjects(array $projectIds): Collection
    {
        if ($projectIds === []) {
            return new Collection;
        }

        return Demo::query()
            ->current()
            ->whereIn('project_id', $projectIds)
            ->orderBy('id')
            ->get()
            ->keyBy('project_id')
            ->reject(fn (Demo $demo) => $demo->status === 'converted');
    }

    /** @return array{ends_at:string|null,status:string} */
    public static function summary(Demo $demo): array
    {
        return [
            'ends_at' => $demo->ends_at?->toIso8601String(),
            'status' => $demo->effectiveStatus(),
        ];
    }
}
