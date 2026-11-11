<?php

namespace App\Domain\Eshs;

use App\Domain\Configuration\ConfigurationRegistry;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Every ESHS dropdown is configuration: config/sasa.php holds the defaults and
 * the registry lets an organisation or a project replace any list. The
 * contractor and subcontractor lists are empty by default — each project
 * names its own.
 */
final class EshsConfiguration
{
    public const CONTRACTORS = 'eshs.contractors';

    public const SUBCONTRACTORS = 'eshs.subcontractors';

    public const CATEGORIES = 'eshs.categories';

    public const ANOMALY_TYPES = 'eshs.anomaly_types';

    public const RISK_RATINGS = 'eshs.risk_ratings';

    public const ROOT_CAUSES = 'eshs.root_causes';

    public function __construct(private readonly ConfigurationRegistry $registry) {}

    /** @return array<int,string> */
    public function contractors(Project $project): array
    {
        return $this->stringList($this->value($project, self::CONTRACTORS, []));
    }

    /** @return array<int,string> */
    public function subcontractors(Project $project): array
    {
        return $this->stringList($this->value($project, self::SUBCONTRACTORS, []));
    }

    /** @return array<string,array{label:string,subcategories:array<int,string>}> */
    public function categories(Project $project): array
    {
        $categories = (array) $this->value($project, self::CATEGORIES, []);

        return collect($categories)
            ->map(fn ($category, $key) => [
                'label' => (string) ($category['label'] ?? ucfirst((string) $key)),
                'subcategories' => $this->stringList($category['subcategories'] ?? []),
            ])
            ->all();
    }

    /** @return array<int,string> */
    public function anomalyTypes(Project $project): array
    {
        return $this->stringList($this->value($project, self::ANOMALY_TYPES, []));
    }

    /** @return array<string,string> key => label */
    public function riskRatings(Project $project): array
    {
        return (array) $this->value($project, self::RISK_RATINGS, []);
    }

    /** @return array<string,array<int,string>> heading => causes */
    public function rootCauses(Project $project): array
    {
        return collect((array) $this->value($project, self::ROOT_CAUSES, []))
            ->map(fn ($causes) => $this->stringList($causes))
            ->all();
    }

    /** Everything a form or filter needs, in display order. */
    public function options(Project $project): array
    {
        return [
            'contractors' => $this->contractors($project),
            'subcontractors' => $this->subcontractors($project),
            'categories' => collect($this->categories($project))
                ->map(fn ($category, $key) => ['key' => $key, 'label' => $category['label'], 'subcategories' => $category['subcategories']])
                ->values()->all(),
            'anomaly_types' => $this->anomalyTypes($project),
            'risk_ratings' => collect($this->riskRatings($project))
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values()->all(),
            'root_causes' => collect($this->rootCauses($project))
                ->map(fn ($causes, $group) => ['group' => $group, 'causes' => $causes])
                ->values()->all(),
            'statuses' => [
                ['key' => 'open', 'label' => 'Open'],
                ['key' => 'in_progress', 'label' => 'In progress'],
                ['key' => 'awaiting_verification', 'label' => 'Awaiting verification'],
                ['key' => 'closed', 'label' => 'Closed'],
            ],
        ];
    }

    public function categoryLabel(Project $project, ?string $key): ?string
    {
        return $key === null ? null : ($this->categories($project)[$key]['label'] ?? ucfirst($key));
    }

    /** Settings editable by a configuration manager: the two company lists. */
    public function settings(Project $project): array
    {
        return [
            'contractors' => $this->contractors($project),
            'subcontractors' => $this->subcontractors($project),
        ];
    }

    /** @param array{contractors?:array<int,string>,subcontractors?:array<int,string>} $data */
    public function updateSettings(Project $project, array $data): array
    {
        DB::transaction(function () use ($project, $data) {
            foreach ([self::CONTRACTORS => 'contractors', self::SUBCONTRACTORS => 'subcontractors'] as $key => $field) {
                if (array_key_exists($field, $data)) {
                    $this->registry->set($key, $this->stringList($data[$field] ?? []), $project->organisation_id, $project->id);
                }
            }
        });

        return $this->settings($project);
    }

    private function value(Project $project, string $key, mixed $default): mixed
    {
        return $this->registry->get($key, $project->organisation_id, $project->id, $default) ?? $default;
    }

    /** Trimmed, de-duplicated, non-empty strings in their given order. */
    private function stringList(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->filter(fn ($value) => is_scalar($value))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}
