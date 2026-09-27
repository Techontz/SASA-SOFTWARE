<?php

namespace Database\Seeders;

use App\Domain\Notification\NotificationEvents;
use App\Domain\Reporting\ReportService;
use App\Models\GrievanceCategory;
use App\Models\Holiday;
use App\Models\Location;
use App\Models\NotificationRule;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Models\WorkingCalendar;
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
                if ($definition['role'] === 'auditor' && $index > 0) {
                    continue;
                }

                // A field officer on the transmission line is read-only on the mine.
                $roleForProject = ($definition['role'] === 'field_officer' && $index === 1)
                    ? $this->roles['auditor']
                    : $role;

                ProjectMembership::updateOrCreate(
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
            }

            $created[$definition['email']] = ['role' => $role->name, 'email' => $definition['email']];
        }

        return $created;
    }

    private function seedProjectFoundations(Project $project): void
    {
        $this->seedLocations($project);
        $calendar = $this->seedCalendar($project);
        $this->seedCategories($project);
        $this->seedSlaPolicies($project, $calendar);
        $this->seedNotificationRules($project);
        $this->seedReportDefinitions($project);
    }

    private function seedLocations(Project $project): void
    {
        $tree = match ($project->code) {
            'NBT-400KV' => [
                'Singida' => [
                    'Iramba' => ['Kiomboi' => ['Kiomboi', 'Ndago', 'Mtoa'], 'Shelui' => ['Shelui', 'Tulya']],
                    'Mkalama' => ['Nduguti' => ['Nduguti', 'Gumanga'], 'Iguguno' => ['Iguguno', 'Msingi']],
                ],
                'Shinyanga' => [
                    'Kahama' => ['Isaka' => ['Isaka', 'Nyandekwa'], 'Ushetu' => ['Ushetu', 'Bulige']],
                ],
                'Mwanza' => [
                    'Ilemela' => ['Buswelu' => ['Buswelu', 'Kahama', 'Nyakato'], 'Kirumba' => ['Kirumba', 'Igoma']],
                    'Magu' => ['Kisesa' => ['Kisesa', 'Nyanguge'], 'Nyanguge' => ['Bujashi', 'Sukuma']],
                ],
            ],
            'KG-GOLD' => [
                'Tanga' => [
                    'Kilindi' => ['Songe' => ['Songe', 'Kwediboma', 'Mkindi'], 'Kibirashi' => ['Kibirashi', 'Lulago']],
                    'Handeni' => ['Kwamsisi' => ['Kwamsisi', 'Misima']],
                ],
            ],
            default => [
                'Mwanza' => [
                    'Nyamagana' => ['Mbugani' => ['Mbugani', 'Mkuyuni'], 'Igogo' => ['Igogo', 'Butimba']],
                ],
                'Geita' => [
                    'Geita Town' => ['Katoro' => ['Katoro', 'Bukoli'], 'Nyankumbu' => ['Nyankumbu']],
                ],
            ],
        };

        $country = Location::firstOrCreate(
            ['organisation_id' => $this->organisation->id, 'project_id' => null, 'level' => 'country', 'name' => 'Tanzania', 'parent_id' => null],
            ['code' => 'TZ', 'status' => 'active']
        );

        foreach ($tree as $regionName => $districts) {
            $region = Location::firstOrCreate(
                ['organisation_id' => $this->organisation->id, 'project_id' => null, 'level' => 'region', 'name' => $regionName, 'parent_id' => $country->id],
                ['status' => 'active']
            );

            foreach ($districts as $districtName => $wards) {
                $district = Location::firstOrCreate(
                    ['organisation_id' => $this->organisation->id, 'project_id' => null, 'level' => 'district', 'name' => $districtName, 'parent_id' => $region->id],
                    ['status' => 'active']
                );

                foreach ($wards as $wardName => $villages) {
                    $ward = Location::firstOrCreate(
                        ['organisation_id' => $this->organisation->id, 'project_id' => null, 'level' => 'ward', 'name' => $wardName, 'parent_id' => $district->id],
                        ['status' => 'active']
                    );

                    foreach ($villages as $villageName) {
                        Location::firstOrCreate(
                            ['organisation_id' => $this->organisation->id, 'project_id' => null, 'level' => 'village', 'name' => $villageName, 'parent_id' => $ward->id],
                            [
                                'status' => 'active',
                                'estimated_population' => rand(800, 6500),
                                'population_profile' => ['vulnerable_share_percent' => rand(8, 22)],
                                'latitude' => -1 * (rand(200, 700) / 100),
                                'longitude' => rand(3200, 3600) / 100,
                            ]
                        );
                    }
                }
            }
        }
    }

    private function seedCalendar(Project $project): WorkingCalendar
    {
        $calendar = WorkingCalendar::updateOrCreate(
            ['organisation_id' => $this->organisation->id, 'project_id' => $project->id],
            [
                'name' => $project->name.' working calendar',
                'country' => 'TZ',
                'timezone' => 'Africa/Dar_es_Salaam',
                'working_days' => [1, 2, 3, 4, 5],
                'work_start' => '08:00',
                'work_end' => '17:00',
                'is_default' => true,
            ]
        );

        // Tanzanian public holidays. "7 working days" means nothing without them.
        $holidays = [
            ['01-01', 'New Year\'s Day'],
            ['01-12', 'Zanzibar Revolution Day'],
            ['04-07', 'Karume Day'],
            ['04-26', 'Union Day'],
            ['05-01', 'Workers\' Day'],
            ['07-07', 'Saba Saba'],
            ['08-08', 'Nane Nane (Farmers\' Day)'],
            ['10-14', 'Nyerere Day'],
            ['12-09', 'Independence Day'],
            ['12-25', 'Christmas Day'],
            ['12-26', 'Boxing Day'],
        ];

        foreach ($holidays as [$monthDay, $name]) {
            Holiday::updateOrCreate(
                ['working_calendar_id' => $calendar->id, 'date' => now()->year.'-'.$monthDay],
                ['name' => $name, 'recurs_annually' => true]
            );
        }

        return $calendar;
    }

    /** The category set is taken verbatim from the source document. */
    private function seedCategories(Project $project): void
    {
        $categories = [
            ['environmental_health_safety', 'Environmental, Health & Safety', 3, false, [
                'dust_air_quality' => 'Dust and air quality',
                'noise_vibration' => 'Noise and vibration',
                'water_quality' => 'Water quality and access',
                'waste_management' => 'Waste management',
                'road_safety' => 'Road safety and traffic',
                'occupational_safety' => 'Occupational health and safety',
                'blasting' => 'Blasting and ground movement',
            ]],
            ['land_assets_livelihoods', 'Land, Assets & Livelihoods', 3, false, [
                'compensation_amount' => 'Compensation amount disputed',
                'compensation_delay' => 'Compensation delayed',
                'crop_damage' => 'Crop or tree damage',
                'structure_damage' => 'Damage to structures',
                'access_restriction' => 'Loss of access to land',
                'resettlement' => 'Resettlement and relocation',
                'livestock' => 'Livestock and grazing',
            ]],
            ['community_relations_engagement', 'Community Relations & Engagement', 2, false, [
                'information_disclosure' => 'Information not disclosed',
                'consultation_quality' => 'Consultation not meaningful',
                'unfulfilled_promise' => 'Promise not kept',
                'contractor_behaviour' => 'Contractor behaviour in the community',
                'access_to_services' => 'Disruption to community services',
            ]],
            ['labor_hr_industrial_relations', 'Labor, HR & Industrial Relations', 3, false, [
                'wages' => 'Wages and payment',
                'contracts' => 'Contract terms',
                'working_hours' => 'Working hours and rest',
                'dismissal' => 'Dismissal and discipline',
                'ppe' => 'Personal protective equipment',
                'union' => 'Freedom of association',
                'accommodation' => 'Worker accommodation',
            ]],
            ['human_rights_workplace_conduct', 'Human Rights & Workplace Conduct', 5, true, [
                'sea_sh' => 'Sexual exploitation, abuse and harassment (SEA/SH)',
                'retaliation' => 'Retaliation against a complainant',
                'discrimination' => 'Discrimination',
                'child_labour' => 'Child labour',
                'forced_labour' => 'Forced labour',
                'security_conduct' => 'Conduct of security personnel',
            ]],
            ['cultural_heritage', 'Cultural Heritage', 4, false, [
                'sacred_sites' => 'Sacred or ritual sites',
                'graves' => 'Graves and burial grounds',
                'chance_finds' => 'Chance archaeological finds',
                'cultural_practice' => 'Disruption to cultural practice',
            ]],
            ['local_employment_economic', 'Local Employment & Economic Opportunities', 2, false, [
                'hiring_process' => 'Local hiring process',
                'local_content' => 'Local procurement and suppliers',
                'skills_training' => 'Skills and training commitments',
                'business_disruption' => 'Disruption to local business',
            ]],
            ['ethics_compliance', 'Ethics & Compliance', 4, true, [
                'bribery' => 'Bribery and corruption',
                'fraud' => 'Fraud or theft',
                'conflict_of_interest' => 'Conflict of interest',
                'misuse_of_position' => 'Misuse of position',
            ]],
        ];

        foreach ($categories as $index => [$key, $name, $defaultSeverity, $restricted, $subcategories]) {
            $category = GrievanceCategory::updateOrCreate(
                ['organisation_id' => $this->organisation->id, 'project_id' => $project->id, 'key' => $key],
                [
                    'name' => $name,
                    'sort_order' => $index,
                    'default_severity' => $defaultSeverity,
                    'is_restricted' => $restricted,
                    'handling_groups' => $restricted ? ['restricted_handling'] : null,
                    'aggregate_reporting_only' => $restricted,
                ]
            );

            foreach (array_values($subcategories) as $subIndex => $subName) {
                $subKey = array_keys($subcategories)[$subIndex];

                GrievanceCategory::updateOrCreate(
                    ['organisation_id' => $this->organisation->id, 'project_id' => $project->id, 'key' => $key.'.'.$subKey],
                    [
                        'parent_id' => $category->id,
                        'name' => $subName,
                        'sort_order' => $subIndex,
                        'default_severity' => $defaultSeverity,
                    ]
                );
            }
        }
    }

    private function seedSlaPolicies(Project $project, WorkingCalendar $calendar): void
    {
        $escalateTo = $this->roles['project_admin'] ?? null;

        foreach (config('sasa.sla.defaults') as $clock => $default) {
            SlaPolicy::updateOrCreate(
                [
                    'organisation_id' => $this->organisation->id,
                    'project_id' => $project->id,
                    'clock' => $clock,
                    'category_id' => null,
                    'severity' => null,
                ],
                [
                    'unit' => $default['unit'],
                    'target_value' => $default['value'],
                    'working_calendar_id' => $calendar->id,
                    'reminder_thresholds' => config('sasa.sla.reminder_thresholds'),
                    'escalate_to_role_id' => $escalateTo?->id,
                    'is_active' => true,
                    'specificity' => SlaPolicy::computeSpecificity(null, null, $project->id),
                ]
            );
        }

        // Serious cases get a tighter standard. Configuration, not code.
        foreach (config('sasa.sla.severity_overrides') as $severity => $clocks) {
            foreach ($clocks as $clock => $value) {
                SlaPolicy::updateOrCreate(
                    [
                        'organisation_id' => $this->organisation->id,
                        'project_id' => $project->id,
                        'clock' => $clock,
                        'category_id' => null,
                        'severity' => $severity,
                    ],
                    [
                        'unit' => 'working_days',
                        'target_value' => $value,
                        'working_calendar_id' => $calendar->id,
                        'reminder_thresholds' => [40, 70],
                        'escalate_to_role_id' => $escalateTo?->id,
                        'is_active' => true,
                        'specificity' => SlaPolicy::computeSpecificity(null, $severity, $project->id),
                    ]
                );
            }
        }
    }

    private function seedNotificationRules(Project $project): void
    {
        foreach (NotificationEvents::defaults() as $default) {
            NotificationRule::updateOrCreate(
                [
                    'organisation_id' => $this->organisation->id,
                    'project_id' => $project->id,
                    'event_key' => $default['event_key'],
                ],
                array_merge($default, ['is_active' => true])
            );
        }
    }

    private function seedReportDefinitions(Project $project): void
    {
        foreach (ReportService::systemDefinitions() as $definition) {
            ReportDefinition::updateOrCreate(
                [
                    'organisation_id' => $this->organisation->id,
                    'project_id' => $project->id,
                    'key' => $definition['key'],
                ],
                array_merge($definition, ['is_system' => true, 'is_active' => true])
            );
        }
    }
}
