<?php

namespace App\Domain\Incident;

use App\Domain\Configuration\ConfigurationRegistry;
use App\Models\Incident;
use App\Models\Project;

/**
 * Every dropdown on the Incident Notification Report, resolved for one project.
 *
 * The categories, types and consequence levels are the client's standard list
 * (config/sasa.php `incidents`); the names lists — companies, contractor
 * managers, resident engineers — differ per project and are set through the
 * configuration registry.
 */
final class IncidentOptions
{
    /** Registry key => config default path. */
    public const LISTS = [
        'companies' => 'incident_companies',
        'contractor_managers' => 'incident_contractor_managers',
        'resident_engineers' => 'incident_resident_engineers',
    ];

    public function __construct(private readonly ConfigurationRegistry $configuration) {}

    /** @return array<int,string> */
    public function list(Project $project, string $name): array
    {
        $key = self::LISTS[$name];

        $value = $this->configuration->get($key, $project->organisation_id, $project->id, config("sasa.incidents.{$name}", []));

        return collect(is_array($value) ? $value : [])
            ->map(fn ($entry) => trim((string) $entry))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int,array{key:string,label:string}> */
    public function categories(Project $project): array
    {
        return array_values($this->configuration->get('incident_categories', $project->organisation_id, $project->id, config('sasa.incidents.categories', [])));
    }

    /** @return array<int,array{key:string,label:string,category:?string,serious?:bool}> */
    public function types(Project $project): array
    {
        return array_values($this->configuration->get('incident_types', $project->organisation_id, $project->id, config('sasa.incidents.types', [])));
    }

    /** @return array<int,string> */
    public function categoryKeys(Project $project): array
    {
        return array_column($this->categories($project), 'key');
    }

    /** @return array<int,string> */
    public function typeKeys(Project $project): array
    {
        return array_column($this->types($project), 'key');
    }

    /** @return array<int,string> level => label */
    public function levels(): array
    {
        return config('sasa.incidents.consequence_levels', []);
    }

    public function categoryLabel(?string $key): ?string
    {
        return $key === null ? null : (collect(config('sasa.incidents.categories'))->firstWhere('key', $key)['label'] ?? $key);
    }

    public function typeLabel(?string $key): ?string
    {
        return $key === null ? null : (collect(config('sasa.incidents.types'))->firstWhere('key', $key)['label'] ?? $key);
    }

    public function levelLabel(?int $level): ?string
    {
        return $level === null ? null : ($this->levels()[$level] ?? "Level {$level}");
    }

    /** The whole form vocabulary, for the client. */
    public function forProject(Project $project): array
    {
        return [
            'categories' => $this->categories($project),
            'types' => collect($this->types($project))->map(fn ($type) => [
                'key' => $type['key'],
                'label' => $type['label'],
                'category' => $type['category'] ?? null,
                'serious' => (bool) ($type['serious'] ?? false),
            ])->values()->all(),
            'consequence_levels' => collect($this->levels())->map(fn ($label, $level) => [
                'level' => (int) $level, 'label' => $label,
            ])->values()->all(),
            'serious_potential_level' => Incident::seriousLevel(),
            'companies' => $this->list($project, 'companies'),
            'contractor_managers' => $this->list($project, 'contractor_managers'),
            'resident_engineers' => $this->list($project, 'resident_engineers'),
            'statuses' => Incident::STATUSES,
        ];
    }

    /**
     * Replace one or more names lists for this project. Each write goes through
     * the registry, which audits it.
     *
     * @param  array<string,array<int,string>>  $lists
     */
    public function updateLists(Project $project, array $lists): array
    {
        foreach (self::LISTS as $name => $key) {
            if (! array_key_exists($name, $lists)) {
                continue;
            }

            $clean = collect($lists[$name] ?? [])
                ->map(fn ($entry) => trim((string) $entry))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $this->configuration->set($key, $clean, $project->organisation_id, $project->id);
        }

        return $this->forProject($project);
    }
}
