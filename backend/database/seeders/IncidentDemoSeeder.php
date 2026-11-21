<?php

namespace Database\Seeders;

use App\Domain\Demo\DemoDataBuilder;
use App\Models\Incident;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * Incident notifications on the showcase transmission line: the per-project
 * names lists, and fourteen reports across categories, consequence levels and
 * the review lifecycle — two of them serious.
 *
 * The story itself lives in DemoDataBuilder, which also fills demo workspaces.
 */
class IncidentDemoSeeder extends Seeder
{
    public function run(DemoDataBuilder $builder): void
    {
        $project = Project::where('code', 'NBT-400KV')->first();

        if (! $project) {
            $this->command?->warn('Demo project not found, skipping incident demo data.');

            return;
        }

        if (Incident::query()->acrossTenants()->where('project_id', $project->id)->exists()) {
            $this->command?->warn('Incident demo data already present, skipping.');

            return;
        }

        $counts = $builder->incidents($project, ShowcaseCast::load());

        $this->command?->info(sprintf('Seeded %d incident notifications.', $counts['incidents']));
    }
}
