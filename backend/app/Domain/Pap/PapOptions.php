<?php

namespace App\Domain\Pap;

use App\Domain\Configuration\ConfigurationRegistry;
use App\Models\Project;

/**
 * The PAP register's dropdown lists. config/sasa.php `pap` holds the defaults;
 * a project (or its organisation) may override any list through the
 * configuration registry under the key `pap`.
 */
final class PapOptions
{
    /** @var array<int,array<string,mixed>> */
    private array $cache = [];

    public function __construct(private readonly ConfigurationRegistry $registry) {}

    /** @return array<string,mixed> */
    public function all(Project $project): array
    {
        return $this->cache[$project->id] ??= (function () use ($project) {
            $stored = $this->registry->all($project->organisation_id, $project->id)['pap'] ?? [];

            return array_replace(config('sasa.pap', []), is_array($stored) ? $stored : []);
        })();
    }

    /** @return array<string,string> value => label */
    public function list(Project $project, string $key): array
    {
        $list = $this->all($project)[$key] ?? [];

        return is_array($list) ? $list : [];
    }

    /** @return array<int,string> */
    public function values(Project $project, string $key): array
    {
        return array_map('strval', array_keys($this->list($project, $key)));
    }

    public function label(Project $project, string $key, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->list($project, $key)[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }
}
