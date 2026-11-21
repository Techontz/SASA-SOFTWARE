<?php

namespace Database\Seeders;

use App\Domain\Demo\DemoDataBuilder;
use App\Models\Project;
use App\Models\Stakeholder;
use Illuminate\Database\Seeder;

/**
 * The operational history on the showcase transmission line: a register, an
 * engagement programme, concerns that became commitments, and a case load in
 * every state of the lifecycle — including breaches, escalations and a
 * reopened case, because a demo where everything is green teaches nothing.
 *
 * The story itself lives in DemoDataBuilder, which also fills demo workspaces.
 */
class DemoOperationsSeeder extends Seeder
{
    public function run(DemoDataBuilder $builder): void
    {
        $project = Project::where('code', 'NBT-400KV')->firstOrFail();

        if (Stakeholder::where('project_id', $project->id)->exists()) {
            $this->command?->warn('Operational demo data already present, skipping.');

            return;
        }

        $counts = $builder->operations($project, ShowcaseCast::load(), false, ['Singida', 'Shinyanga', 'Mwanza']);

        $this->command?->info(sprintf(
            'Seeded %d stakeholders, %d engagements, %d grievances and %d standalone commitments on %s.',
            $counts['stakeholders'] ?? 0,
            $counts['engagements'] ?? 0,
            $counts['grievances'] ?? 0,
            $counts['commitments'] ?? 0,
            $project->name,
        ));
    }
}
