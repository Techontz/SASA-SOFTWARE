<?php

namespace Database\Seeders;

use App\Domain\Demo\DemoDataBuilder;
use App\Domain\Tenancy\ProjectFoundations;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Realistic demo data for a Tanzanian transmission project and a mine.
 *
 * The point is that the product explains itself: a first-time viewer should be
 * able to open the executive dashboard and understand what SASA is for without
 * anybody narrating it.
 */
class DemoSeeder extends Seeder
{
    private Organisation $organisation;

    /** @var array<string,Role> */
    private array $roles = [];

    public function run(): void
    {
        $this->roles = Role::whereNull('organisation_id')->get()->keyBy('key')->all();

        $this->organisation = Organisation::updateOrCreate(
            ['slug' => 'nyanda-infrastructure'],
            [
                'name' => 'Nyanda Infrastructure Group',
                'country' => 'TZ',
                'timezone' => 'Africa/Dar_es_Salaam',
                'contact_email' => 'social.performance@nyanda.co.tz',
                'contact_phone' => '+255 22 213 4400',
                'brand_color' => '#0F3D46',
                'status' => 'active',
            ]
        );

        $projects = [
            $this->makeProject('NBT-400KV', 'Northern Backbone 400kV Transmission Line', 'transmission',
                'A 412 km double-circuit transmission line and four substations connecting Singida to Mwanza, crossing 38 villages in four districts.',
                '2025-02-01', '2028-06-30'),
            $this->makeProject('KG-GOLD', 'Kilindi Gold Project', 'mining',
                'An open-pit gold operation and processing plant in Tanga Region, with a resettlement programme covering 214 households.',
                '2024-07-01', '2034-12-31'),
            $this->makeProject('LK-WATER', 'Lake Basin Water Supply Programme', 'water',
                'Bulk water transmission and district distribution serving 340,000 people across Mwanza and Geita.',
                '2026-01-15', '2029-09-30'),
        ];

        $users = $this->seedUsers($projects);

        foreach ($projects as $project) {
            $this->seedProjectFoundations($project);
        }

        // Only the first project gets a full operational history — a demo that
        // fills three projects with identical noise teaches nothing.
        $this->call(DemoOperationsSeeder::class);
        $this->call(PapDemoSeeder::class);
        $this->call(EshsDemoSeeder::class);
        $this->call(IncidentDemoSeeder::class);

        $this->command?->newLine();
        $this->command?->info('Demo organisation: '.$this->organisation->name);
        $this->command?->table(
            ['Role', 'Email', 'Password'],
            collect($users)->map(fn ($user, $key) => [$user['role'], $user['email'], 'password'])->values()->all()
        );
    }

    private function makeProject(string $code, string $name, string $sector, string $description, string $start, string $end): Project
    {
        return Project::updateOrCreate(
            ['organisation_id' => $this->organisation->id, 'code' => $code],
            [
                'name' => $name,
                'description' => $description,
                'country' => 'TZ',
                'sector' => $sector,
                'timezone' => 'Africa/Dar_es_Salaam',
                'status' => 'active',
                'starts_on' => $start,
                'ends_on' => $end,
                'fiscal_year_start_month' => 7,
            ]
        );
    }

    /** One person per documented role, plus a couple of extras for assignment. */
    private function seedUsers(array $projects): array
    {
        $definitions = [
            ['name' => 'Asha Mwakalinga', 'email' => 'admin@sasa.test', 'role' => 'system_administrator', 'title' => 'Platform administrator', 'system' => true],
            ['name' => 'Joseph Kimaro', 'email' => 'project.admin@sasa.test', 'role' => 'project_admin', 'title' => 'Project administrator'],
            ['name' => 'Dr Amina Rugemalira', 'email' => 'executive@sasa.test', 'role' => 'management', 'title' => 'Director, Social Performance'],
            ['name' => 'Peter Massawe', 'email' => 'pm@sasa.test', 'role' => 'project_management', 'title' => 'Project manager'],
            ['name' => 'Grace Ndosi', 'email' => 'grievance@sasa.test', 'role' => 'grievance_officer', 'title' => 'Grievance officer'],
            ['name' => 'Emmanuel Shirima', 'email' => 'cro@sasa.test', 'role' => 'community_relations_officer', 'title' => 'Community relations officer'],
            ['name' => 'Neema Lyimo', 'email' => 'hr@sasa.test', 'role' => 'hr_officer', 'title' => 'HR officer'],
            ['name' => 'Salum Bakari', 'email' => 'hse@sasa.test', 'role' => 'hse_officer', 'title' => 'HSE officer'],
            ['name' => 'Frank Mollel', 'email' => 'security@sasa.test', 'role' => 'security_officer', 'title' => 'Security officer'],
            ['name' => 'Zainabu Hamisi', 'email' => 'field@sasa.test', 'role' => 'field_officer', 'title' => 'Field officer'],
            ['name' => 'Mariam Juma', 'email' => 'field2@sasa.test', 'role' => 'field_officer', 'title' => 'Field officer'],
            ['name' => 'Robert Chenge', 'email' => 'auditor@sasa.test', 'role' => 'auditor', 'title' => 'Lender representative (read-only)'],
            ['name' => 'Kisare Lutambi', 'email' => 'contractor@sasa.test', 'role' => 'contractor', 'title' => 'Contractor HSE lead', 'contractor' => EshsDemoSeeder::CONTRACTORS[0]],
        ];

        $created = [];

        foreach ($definitions as $definition) {
            $user = User::updateOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'organisation_id' => $this->organisation->id,
                    'job_title' => $definition['title'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'is_system_admin' => $definition['system'] ?? false,
                    'status' => 'active',
                    'locale' => 'en',
                    'phone' => '+255 7'.rand(10, 89).' '.rand(100, 999).' '.rand(100, 999),
                ]
            );

            $role = $this->roles[$definition['role']];

            $handlingGroups = match ($definition['role']) {
                'system_administrator' => ['*'],
                'grievance_officer', 'hr_officer' => ['general', 'restricted_handling'],
                'auditor' => [],
                default => ['general'],
            };

            foreach ($projects as $index => $project) {
                // The auditor sees only the first project — role is per project,
                // which is exactly the point.
                if (in_array($definition['role'], ['auditor', 'contractor'], true) && $index > 0) {
                    continue;
                }

                // A field officer on the transmission line is read-only on the mine.
                $roleForProject = ($definition['role'] === 'field_officer' && $index === 1)
                    ? $this->roles['auditor']
                    : $role;

                $membership = ProjectMembership::updateOrCreate(
                    ['project_id' => $project->id, 'user_id' => $user->id],
                    [
                        'organisation_id' => $this->organisation->id,
                        'role_id' => $roleForProject->id,
                        'handling_groups' => $handlingGroups,
                        'status' => 'active',
                        'is_default' => $index === 0,
                        'joined_at' => now()->subMonths(6),
                    ]
                );

                // Contractor personnel see only their own company's ESHS anomalies.
                if (isset($definition['contractor'])) {
                    $membership->forceFill(['contractor_name' => $definition['contractor']])->save();
                }
            }

            $created[$definition['email']] = ['role' => $role->name, 'email' => $definition['email']];
        }

        return $created;
    }

    private function seedProjectFoundations(Project $project): void
    {
        app(DemoDataBuilder::class)->locations(
            $this->organisation->id,
            DemoDataBuilder::LOCATION_TREES[$project->code] ?? DemoDataBuilder::LOCATION_TREES['default'],
        );

        app(ProjectFoundations::class)->apply($project);
    }
}
