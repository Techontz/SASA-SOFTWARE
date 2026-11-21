<?php

namespace Database\Seeders;

use App\Domain\Demo\DemoDataBuilder;
use App\Models\EshsAnomaly;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * ESHS contractor assurance for the showcase project: three contractors and
 * two subcontractors configured, and eighteen anomalies across every category,
 * risk rating and stage — some overdue, some awaiting verification, some
 * closed on evidence.
 *
 * The story itself lives in DemoDataBuilder, which also fills demo workspaces.
 */
class EshsDemoSeeder extends Seeder
{
    public const CONTRACTORS = DemoDataBuilder::CONTRACTORS;

    public const SUBCONTRACTORS = DemoDataBuilder::SUBCONTRACTORS;

    public function run(DemoDataBuilder $builder): void
    {
        $project = Project::where('code', 'NBT-400KV')->first();

        if (! $project) {
            $this->command?->warn('Demo project NBT-400KV not found, skipping ESHS demo data.');

            return;
        }

        $present = EshsAnomaly::acrossTenants()->where('project_id', $project->id)->exists();
        $counts = $builder->eshs($project, ShowcaseCast::load(), refreshOnly: $present);

        if ($present) {
            $this->command?->warn('ESHS demo anomalies already present: contractor lists refreshed, anomalies skipped.');

            return;
        }

        $this->command?->info('ESHS demo: '.$counts['eshs_anomalies'].' anomalies across '.count(self::CONTRACTORS).' contractors.');
    }
}
