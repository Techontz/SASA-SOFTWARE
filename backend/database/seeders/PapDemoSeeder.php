<?php

namespace Database\Seeders;

use App\Domain\Demo\DemoDataBuilder;
use App\Models\Pap;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * A resettlement register in mid-flight on the showcase transmission line:
 * twelve PAPs across four villages, surveyed, inventoried and valued, with
 * payments in every state, two households relocated, a livelihood programme
 * under way and three consultation meetings.
 *
 * The story itself lives in DemoDataBuilder, which also fills demo workspaces.
 */
class PapDemoSeeder extends Seeder
{
    public function run(DemoDataBuilder $builder): void
    {
        $project = Project::where('code', 'NBT-400KV')->first();

        if (! $project) {
            $this->command?->warn('Demo project NBT-400KV not found. Run DemoSeeder first.');

            return;
        }

        if (Pap::acrossTenants()->where('project_id', $project->id)->exists()) {
            $this->command?->warn('PAP demo data already present, skipping.');

            return;
        }

        $counts = $builder->paps($project, ShowcaseCast::load());

        if ($counts === []) {
            $this->command?->warn('No villages in the location hierarchy, skipping PAP demo data.');

            return;
        }

        $this->command?->info(sprintf(
            'Seeded %d PAPs (with stakeholder records), their inventories, valuations and payments, and 3 consultations on %s.',
            $counts['paps'],
            $project->name,
        ));
    }
}
