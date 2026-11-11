<?php

namespace Database\Seeders;

use App\Domain\Configuration\ConfigurationRegistry;
use App\Models\Incident;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Incident notifications on the demo transmission line: the per-project
 * names lists, and fourteen reports across categories, consequence levels and
 * the review lifecycle — two of them serious.
 */
class IncidentDemoSeeder extends Seeder
{
    public function run(ConfigurationRegistry $configuration): void
    {
        $project = Project::where('code', 'NBT-400KV')->first();

        if (! $project) {
            $this->command?->warn('Demo project not found — skipping incident demo data.');

            return;
        }

        if (Incident::query()->acrossTenants()->where('project_id', $project->id)->exists()) {
            $this->command?->warn('Incident demo data already present — skipping.');

            return;
        }

        $companies = ['TBEA Tanzania Ltd', 'Kalpataru Power Transmission', 'Mwanza Civil Works Co.'];
        $managers = ['Joseph Mrema', 'Rahul Deshpande'];
        $engineers = ['Eng. Neema Kweka', 'Eng. Baraka Massawe'];

        $users = User::whereIn('email', ['hse@sasa.test', 'field@sasa.test', 'field2@sasa.test', 'security@sasa.test', 'pm@sasa.test'])
            ->get()->keyBy('email');
        $fallback = User::query()->orderBy('id')->first();
        $user = fn (string $email) => $users[$email] ?? $fallback;

        Auth::login($user('pm@sasa.test'));

        $configuration->set('incident_companies', $companies, $project->organisation_id, $project->id);
        $configuration->set('incident_contractor_managers', $managers, $project->organisation_id, $project->id);
        $configuration->set('incident_resident_engineers', $engineers, $project->organisation_id, $project->id);

        $villages = Location::where('organisation_id', $project->organisation_id)
            ->where('level', 'village')->orderBy('id')->get()->values();

        // [days ago, hour, company, site, category, type, actual, potential, status, reporter, summary, review note]
        $rows = [
            [2, 9, 0, 'Tower T-118 foundation pit', 'near_miss', 'near_miss', 0, 3, 'submitted', 'field@sasa.test',
                'Excavator bucket swung within a metre of a banksman who had stepped inside the exclusion zone to retrieve a tape. Work stopped and the zone re-briefed.', null],
            [4, 14, 1, 'Stringing yard, Shinyanga section', 'personal_injury', 'hs_fac', 1, 2, 'submitted', 'field2@sasa.test',
                'Linesman cut his left palm on a conductor strand while handling a drum. Cleaned and dressed by the site first aider; returned to work.', null],
            [6, 11, 2, 'Access road km 14, near Ibadakuli', 'materials_loss', 'ml_road_traffic', 1, 3, 'under_review', 'field@sasa.test',
                'Water bowser reversed into a parked pickup at the batching area. Rear light cluster and tailgate damaged. No injuries.',
                'Reversing without a banksman. Contractor to retrain drivers and fit reversing alarms on all bowsers.'],
            [8, 7, 0, 'Camp fuel station, Singida', 'environmental', 'env_spill_hydrocarbon', 2, 3, 'under_review', 'hse@sasa.test',
                'Roughly 120 litres of diesel spilled when a transfer hose split during refuelling. Contained by the bund; contaminated soil excavated and drummed.',
                'Hose inspection log not kept. Spill kit restocked; contractor to replace all transfer hoses older than 12 months.'],
            [11, 16, 1, 'Tower T-097, Mwanza section', 'personal_injury', 'hs_lti', 3, 4, 'under_review', 'hse@sasa.test',
                'Rigger fell approximately 2.5 m from the tower climbing ladder while repositioning his lanyard and fractured his right wrist. Taken to Bugando hospital; off work for at least three weeks.',
                'Serious potential. Stand-down held across all tower crews; 100% tie-off audit under way.'],
            [13, 10, 2, 'Village access track near the primary school', 'social', 'soc_asset_damage', 1, 2, 'closed', 'field2@sasa.test',
                'Low-bed trailer clipped and broke a section of a household\'s fence and two banana plants while turning at the junction.',
                'Household compensated in agreement with the village chairperson; turning point widened.'],
            [16, 22, 0, 'Laydown yard, Ilemela', 'security', 'sec_theft', 2, 2, 'closed', 'security@sasa.test',
                'Twelve galvanised angle members (approximately 400 kg) stolen from the laydown yard overnight. Fence cut on the eastern side.',
                'Reported to Ilemela police. Fence repaired, additional night guard deployed, lighting added.'],
            [19, 13, 1, 'Tower T-131 erection site', 'materials_loss', 'ml_dropped_objects', 0, 3, 'closed', 'field@sasa.test',
                'A 3 kg bolt bag fell about 20 m from the tower top, landing inside the drop zone but within two metres of a ground worker. No injury.',
                'Tool and bag tethering made mandatory; drop zone extended to 1.5× height.'],
            [22, 8, 2, 'Riverside crossing, Manonga river', 'environmental', 'env_waste_management', 1, 2, 'closed', 'hse@sasa.test',
                'Cement bags and food packaging found burned in an open pit beside the river crossing work front.',
                'Waste collection points set up at each work front; toolbox talk on the waste management plan.'],
            [26, 15, 0, 'Camp, Singida', 'personal_injury', 'hs_oi', 1, 2, 'submitted', 'hse@sasa.test',
                'Two camp kitchen staff reported respiratory irritation after charcoal stove use in a poorly ventilated store.', null],
            [33, 18, 1, 'Community borehole, Nyakato', 'social', 'soc_local_workforce_health', 1, 3, 'under_review', 'field2@sasa.test',
                'Villagers report workers washing vehicles at the community borehole, leaving oily runoff around the drinking-water point.',
                'Contractor instructed to stop immediately; water quality sample taken.'],
            [41, 6, 2, 'Tower T-060, Shinyanga section', 'near_miss', 'ml_lifting', 0, 3, 'closed', 'field@sasa.test',
                'Crane outrigger pad sank into soft ground during a lift; the lift was lowered safely before the crane became unstable.',
                'Ground bearing check now required before every lift; timber mats issued.'],
            [52, 12, 0, 'Access road km 3', 'materials_loss', 'ml_serious_road_traffic', 3, 5, 'closed', 'security@sasa.test',
                'Contractor pickup overturned after swerving to avoid a motorcycle. Driver and passenger hospitalised overnight; vehicle written off.',
                'Speed limiters fitted to all light vehicles; journey management plan reissued.'],
            [64, 9, 1, 'Substation extension, Mwanza', 'personal_injury', 'hs_mtc', 2, 3, 'closed', 'hse@sasa.test',
                'Electrician received a foreign body in the eye while grinding without a face shield; removed at the clinic.',
                'Face shields issued with every grinder; PPE spot checks increased.'],
        ];

        foreach ($rows as $index => [$daysAgo, $hour, $company, $site, $category, $type, $actual, $potential, $status, $reporter, $summary, $note]) {
            $occurredAt = now()->subDays($daysAgo)->setTime($hour, ($index * 7) % 60);
            $reporterUser = $user($reporter);
            $manager = $user('hse@sasa.test');

            $incident = new Incident;
            $incident->organisation_id = $project->organisation_id;
            $incident->project_id = $project->id;
            $incident->fill([
                'occurred_at' => $occurredAt,
                'company' => $companies[$company],
                'location_id' => $villages->isNotEmpty() ? $villages[$index % $villages->count()]->id : null,
                'site_detail' => $site,
                'category' => $category,
                'incident_type' => $type,
                'actual_consequence' => $actual,
                'potential_consequence' => $potential,
                'summary' => $summary,
                'contractor_manager' => $managers[$index % 2],
                'resident_engineer' => $engineers[($index + 1) % 2],
            ]);
            $incident->forceFill([
                'reported_by' => $reporterUser->id,
                'status' => $status,
                'review_note' => $note,
                'reviewed_by' => $status !== 'submitted' ? $manager->id : null,
                'reviewed_at' => $status !== 'submitted' ? $occurredAt->copy()->addDay() : null,
                'closed_by' => $status === 'closed' ? $manager->id : null,
                'closed_at' => $status === 'closed' ? $occurredAt->copy()->addDays(5) : null,
                'created_by' => $reporterUser->id,
                'created_at' => $occurredAt->copy()->addHours(2),
            ]);
            $incident->save();
        }

        $this->command?->info(sprintf('Seeded %d incident notifications.', count($rows)));
    }
}
