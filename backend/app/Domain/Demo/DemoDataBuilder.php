<?php

namespace App\Domain\Demo;

use App\Domain\Audit\AuditLogger;
use App\Domain\Configuration\ConfigurationRegistry;
use App\Domain\Engagement\CommitmentService;
use App\Domain\Engagement\EngagementService;
use App\Domain\Eshs\EshsConfiguration;
use App\Domain\Grievance\GrievanceService;
use App\Domain\Grievance\IntakeEngine;
use App\Domain\Pap\PapConsultationService;
use App\Domain\Pap\PapRecordService;
use App\Domain\Pap\PapService;
use App\Domain\Stakeholder\StakeholderService;
use App\Models\Attachment;
use App\Models\EshsAnomaly;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\Incident;
use App\Models\Location;
use App\Models\Pap;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Realistic sample records for any project: a stakeholder register, an
 * engagement programme with concerns and commitments, a grievance case load in
 * every state of the lifecycle (with SLA clocks), standalone commitments, a
 * resettlement (PAP) register, ESHS contractor anomalies and incident
 * notifications.
 *
 * Two uses:
 *  - the showcase seed (DemoSeeder and friends) — the full story, unchanged;
 *  - a demo workspace for a company that asked for one — `$sample = true`
 *    takes a representative subset (fast), uses only fictional company
 *    names, and gives every person a phone number from an unallocated range.
 *
 * Every record goes through the same domain services a person's entry does,
 * so SLA clocks, references, the stakeholder sync and the audit trail are all
 * exactly what the product produces in real use.
 */
final class DemoDataBuilder
{
    /** Contractors on the showcase project's ESHS register. */
    public const CONTRACTORS = [
        'Kilimanjaro Power Constructors Ltd',
        'Lake Zone Civil Works Ltd',
        'Sino-Tanzania Grid Engineering Co.',
    ];

    public const SUBCONTRACTORS = [
        'Msalato Haulage & Logistics',
        'Ruvu Security Services',
    ];

    /** Showcase incident companies. */
    public const INCIDENT_COMPANIES = ['TBEA Tanzania Ltd', 'Kalpataru Power Transmission', 'Mwanza Civil Works Co.'];

    /** A demo workspace's companies: "mfano" is Swahili for "example". */
    public const SAMPLE_CONTRACTORS = [
        'Mfano Power Constructors Ltd',
        'Mfano Civil Works Ltd',
        'Mfano Grid Engineering Co.',
    ];

    public const SAMPLE_SUBCONTRACTORS = [
        'Mfano Haulage & Logistics',
        'Mfano Security Services',
    ];

    public const SAMPLE_INCIDENT_COMPANIES = ['Mfano Grid Builders Ltd', 'Mfano Line Constructors Ltd', 'Mfano Civil Works Ltd'];

    /** Names in the showcase text that belong to real organisations; a demo workspace never shows them. */
    public const FICTIONAL_NAMES = [
        'TANESCO Regional Office' => 'Regional Power Utility Office',
        'Baraka Construction Ltd' => 'Mfano Construction Ltd',
        'Baraka Construction' => 'Mfano Construction',
        'CRDB Bank' => 'Mfano Bank',
        'NMB Bank' => 'Mfano Savings Bank',
        'Bugando hospital' => 'the regional referral hospital',
        'NBT/' => 'DEMO/',
    ];

    /** The location hierarchy (region → district → ward → villages), per showcase project code. */
    public const LOCATION_TREES = [
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
        'default' => [
            'Mwanza' => [
                'Nyamagana' => ['Mbugani' => ['Mbugani', 'Mkuyuni'], 'Igogo' => ['Igogo', 'Butimba']],
            ],
            'Geita' => [
                'Geita Town' => ['Katoro' => ['Katoro', 'Bukoli'], 'Nyankumbu' => ['Nyankumbu']],
            ],
        ],
    ];

    /** The representative subset a demo workspace gets, by row index into each showcase list. */
    private const SAMPLE = [
        'stakeholders' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
        'engagements' => [0, 1, 2, 3, 6, 9],
        'upcoming_plans' => [4, 9, 16],
        'grievances' => [0, 1, 2, 3, 4, 5, 6, 8, 10, 13],
        'commitments' => [0, 1, 3, 4, 6],
        'paps' => [0, 1, 2, 3, 4, 5, 6, 7],
        'eshs' => [0, 1, 2, 4, 5, 8, 10, 12, 14, 17],
        'incidents' => [0, 1, 2, 3, 5, 6, 8, 12],
    ];

    private bool $sample = false;

    private int $phoneSequence = 0;

    public function __construct(
        private readonly StakeholderService $stakeholders,
        private readonly EngagementService $engagements,
        private readonly CommitmentService $commitments,
        private readonly IntakeEngine $intake,
        private readonly GrievanceService $grievances,
        private readonly PapService $paps,
        private readonly PapRecordService $papRecords,
        private readonly PapConsultationService $papConsultations,
        private readonly EshsConfiguration $eshsConfiguration,
        private readonly ConfigurationRegistry $configuration,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A demo workspace's sample data, played entirely by the one person
     * invited into it. Returns what was created, by kind.
     *
     * @return array<string,int>
     */
    public function buildSample(Project $project, User $user): array
    {
        $cast = DemoCast::single($user);

        return array_merge(
            $this->operations($project, $cast, true),
            $this->paps($project, $cast, true),
            $this->eshs($project, $cast, true),
            $this->incidents($project, $cast, true),
        );
    }

    // ------------------------------------------------------------- locations

    /** @param array<string,array<string,array<string,array<int,string>>>> $tree */
    public function locations(int $organisationId, array $tree): void
    {
        $country = Location::firstOrCreate(
            ['organisation_id' => $organisationId, 'project_id' => null, 'level' => 'country', 'name' => 'Tanzania', 'parent_id' => null],
            ['code' => 'TZ', 'status' => 'active']
        );

        foreach ($tree as $regionName => $districts) {
            $region = Location::firstOrCreate(
                ['organisation_id' => $organisationId, 'project_id' => null, 'level' => 'region', 'name' => $regionName, 'parent_id' => $country->id],
                ['status' => 'active']
            );

            foreach ($districts as $districtName => $wards) {
                $district = Location::firstOrCreate(
                    ['organisation_id' => $organisationId, 'project_id' => null, 'level' => 'district', 'name' => $districtName, 'parent_id' => $region->id],
                    ['status' => 'active']
                );

                foreach ($wards as $wardName => $villages) {
                    $ward = Location::firstOrCreate(
                        ['organisation_id' => $organisationId, 'project_id' => null, 'level' => 'ward', 'name' => $wardName, 'parent_id' => $district->id],
                        ['status' => 'active']
                    );

                    foreach ($villages as $villageName) {
                        Location::firstOrCreate(
                            ['organisation_id' => $organisationId, 'project_id' => null, 'level' => 'village', 'name' => $villageName, 'parent_id' => $ward->id],
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

    // ------------------------------------------------------------ operations

    /**
     * The operational history: a register, an engagement programme, concerns
     * that became commitments, and a case load in every state of the
     * lifecycle — including breaches, escalations and a reopened case,
     * because a demo where everything is green teaches nothing.
     *
     * @param  array<int,string>|null  $regions  limit the villages used to these regions
     * @return array<string,int>
     */
    public function operations(Project $project, DemoCast $cast, bool $sample = false, ?array $regions = null): array
    {
        $this->sample = $sample;

        $villages = Location::where('organisation_id', $project->organisation_id)
            ->where('level', 'village')
            ->when($regions !== null, fn ($q) => $q->whereHas('parent.parent.parent', fn ($r) => $r->whereIn('name', $regions)))
            ->get()->all();

        if ($villages === []) {
            $villages = Location::where('organisation_id', $project->organisation_id)->where('level', 'village')->get()->all();
        }

        if ($villages === [] && ! $sample) {
            $villages = Location::where('level', 'village')->get()->all();
        }

        if ($villages === []) {
            return [];
        }

        $categories = GrievanceCategory::where('project_id', $project->id)->get()->keyBy('key')->all();
        $organiser = $project->organisation?->name ?? 'Project';

        return $this->as($cast->get('cro'), function () use ($project, $cast, $villages, $categories, $organiser) {
            $register = $this->seedStakeholders($project, $cast, $villages);
            $engagements = $this->seedEngagements($project, $cast, $villages, $categories, $register, $organiser);
            $grievances = $this->seedGrievances($project, $cast, $villages, $categories, $register);
            $commitments = $this->seedStandaloneCommitments($project, $cast, $villages, $register);

            return [
                'stakeholders' => count($register),
                'engagements' => $engagements,
                'grievances' => $grievances,
                'commitments' => $commitments,
            ];
        });
    }

    /**
     * @param  array<int,Location>  $villages
     * @return array<int,Stakeholder>
     */
    private function seedStakeholders(Project $project, DemoCast $cast, array $villages): array
    {
        $definitions = $this->pick('stakeholders', [
            ['Mwenyekiti Daniel Masanja', 'traditional_leader', 'high', 'high', 'high', 'high', false, 'Village chairman, Buswelu. Speaks for 1,400 households on the line corridor.'],
            ['Buswelu Village Council', 'community_group', 'high', 'high', 'high', 'high', false, 'The statutory village authority. All formal disclosure runs through it.'],
            ['Neema Charles Mwita', 'individual', 'medium', 'high', 'low', 'high', true, 'Widow farming 1.2 ha directly under the line corridor. Compensation outstanding.'],
            ['Kahama District Council', 'government', 'high', 'medium', 'high', 'medium', false, 'District authority for permits, road access and the community development levy.'],
            ['Shirika la Maendeleo ya Wanawake Ilemela', 'cso', 'medium', 'high', 'low', 'medium', false, 'Women\'s development organisation active across four wards. Runs the FGD programme.'],
            ['Isaka Farmers\' Cooperative', 'community_group', 'medium', 'high', 'medium', 'high', false, 'Represents 340 smallholders whose fields cross the corridor.'],
            ['Baraka Construction Ltd', 'contractor', 'medium', 'medium', 'medium', 'high', false, 'Civil works contractor for towers 118–214. Employs 260 local workers.'],
            ['Elder Mzee Rashid Kiwelu', 'individual', 'high', 'medium', 'medium', 'medium', true, 'Elderly custodian of the Nduguti ritual site. Consulted on all heritage matters.'],
            ['Ndago Water Users Association', 'community_group', 'low', 'high', 'low', 'high', false, 'Manages two boreholes downstream of the substation site.'],
            ['Regional Commissioner\'s Office, Mwanza', 'government', 'high', 'low', 'high', 'low', false, 'Regional oversight. Attends quarterly disclosure meetings only.'],
            ['Hamisi Selemani', 'individual', 'low', 'medium', 'low', 'high', false, 'Shopkeeper in Kiomboi whose access road was closed during tower works.'],
            ['Tulya Youth Group', 'community_group', 'low', 'high', 'low', 'medium', true, 'Represents 90 unemployed young people seeking construction work.'],
            ['Mama Fatuma Ngoyai', 'household', 'low', 'high', 'low', 'high', true, 'Female-headed household of seven, relocated from the substation footprint.'],
            ['Msingi Primary School', 'community_group', 'medium', 'medium', 'low', 'high', false, 'School 300 m from the corridor. Noise and dust during construction.'],
            ['TANESCO Regional Office', 'government', 'high', 'high', 'high', 'medium', false, 'The offtaker and eventual operator of the line.'],
            ['Nyandekwa Grazing Committee', 'community_group', 'medium', 'high', 'medium', 'high', false, 'Manages seasonal cattle routes crossing the corridor.'],
            ['Dr Jane Mahenge', 'individual', 'medium', 'medium', 'medium', 'low', false, 'District medical officer. Consulted on health impacts and camp sanitation.'],
            ['Kirumba Market Traders', 'community_group', 'low', 'medium', 'low', 'medium', false, 'Traders affected by haulage route dust.'],
            ['Bulige Village Council', 'community_group', 'high', 'high', 'medium', 'high', false, 'Village authority for the Ushetu ward crossing.'],
            ['Sukuma Cultural Association', 'cso', 'medium', 'medium', 'low', 'medium', true, 'Advises on cultural heritage and burial site protection.'],
            ['Igoma Ward Executive Officer', 'government', 'medium', 'high', 'high', 'medium', false, 'Ward-level administration and grievance channel host.'],
            ['Amina Juma Nkya', 'individual', 'low', 'high', 'low', 'high', true, 'Person with a mobility disability; requires door-to-door engagement.'],
            ['Mtoa Beekeepers Group', 'community_group', 'low', 'medium', 'low', 'medium', false, 'Hives displaced by right-of-way clearing.'],
            ['Gumanga Health Centre', 'government', 'low', 'medium', 'low', 'high', false, 'Nearest health facility for the construction workforce.'],
            ['Shelui Transporters Association', 'business', 'medium', 'medium', 'low', 'medium', false, 'Local haulage operators seeking project contracts.'],
        ]);

        $owners = [$cast->get('cro')->id, $cast->get('field')->id, $cast->get('field2')->id];
        $languages = ['sw', 'sw', 'sw', 'en'];
        $created = [];

        foreach ($definitions as $index => [$name, $type, $influence, $interest, $power, $impact, $vulnerable, $notes]) {
            $village = $villages[$index % count($villages)];

            $created[] = $this->stakeholders->create($project, [
                'name' => $name,
                'type' => $type,
                'phone' => $this->phone(),
                'email' => $type === 'government' || $type === 'cso'
                    ? strtolower(str_replace([' ', '\''], ['.', ''], explode(',', $name)[0])).'@example.tz'
                    : null,
                'preferred_language' => $languages[$index % count($languages)],
                'preferred_contact_method' => $index % 3 === 0 ? 'phone' : 'in_person',
                'primary_location_id' => $village->id,
                'influence' => $influence,
                'interest' => $interest,
                'power' => $power,
                'impact' => $impact,
                'concerns_expectations' => $notes,
                'is_vulnerable' => $vulnerable,
                'vulnerability_categories' => $vulnerable
                    ? [['elderly', 'disability', 'female_headed_household', 'low_income'][$index % 4]]
                    : [],
                'is_indigenous_or_minority' => $index % 11 === 0,
                'consent_status' => $index % 7 === 0 ? 'not_recorded' : 'granted',
                'consent_basis' => 'consent',
                'consent_date' => $index % 7 === 0 ? null : now()->subMonths(rand(2, 10))->toDateString(),
                'identification_source' => ['census', 'village_meeting', 'self_identified', 'survey'][$index % 4],
                'identification_method' => ['household_survey', 'public_meeting', 'walk_in'][$index % 3],
                'demographics' => [
                    'gender' => $index % 3 === 0 ? 'female' : ($index % 3 === 1 ? 'male' : 'prefer_not_to_say'),
                    'age_band' => ['18_35', '36_60', 'over_60'][$index % 3],
                    'employment' => ['farmer', 'self_employed', 'employed_by_project', 'unemployed'][$index % 4],
                    'language' => $languages[$index % count($languages)],
                ],
                'status' => 'active',
                'review_date' => now()->addDays(rand(-20, 150))->toDateString(),
                'owner_id' => $owners[$index % count($owners)],
                'captured_at' => now()->subMonths(rand(1, 11)),
            ]);
        }

        return $created;
    }

    /**
     * @param  array<int,Location>  $villages
     * @param  array<string,GrievanceCategory>  $categories
     * @param  array<int,Stakeholder>  $register
     */
    private function seedEngagements(Project $project, DemoCast $cast, array $villages, array $categories, array $register, string $organiser): int
    {
        $programme = $this->pick('engagements', [
            ['Corridor disclosure meeting, Buswelu', 'community_meeting', -95, 'on_plan', 180, [
                ['Dust from the access road is reaching the school', 'high', 'environmental_health_safety'],
                ['Compensation for three households is still not paid', 'high', 'land_assets_livelihoods'],
            ], [
                ['Water the access road twice daily during dry-season works', 14, 'high'],
                ['Publish the compensation payment schedule at the village office within two weeks', 14, 'medium'],
            ]],
            ['Focus group discussion with women, Ilemela', 'focus_group', -82, 'on_plan', 42, [
                ['Women are not being told about job opportunities in time', 'medium', 'local_employment_economic'],
            ], [
                ['Post all vacancies at the village office 7 days before closing', 30, 'medium'],
            ]],
            ['Household survey, Isaka corridor', 'household_visit', -74, 'late', 65, [
                ['Crop damage during survey pegging was not recorded', 'medium', 'land_assets_livelihoods'],
            ], [
                ['Re-survey the affected plots with the farmers present', 21, 'high'],
            ]],
            ['Cultural heritage consultation at the Nduguti ritual site', 'community_meeting', -60, 'on_plan', 55, [
                ['Tower 142 is 40 m from the ritual site boundary', 'high', 'cultural_heritage'],
            ], [
                ['Commission a chance-find procedure and brief all contractors before clearing', 45, 'high'],
            ]],
            ['Quarterly disclosure to Kahama District Council', 'public_hearing', -48, 'on_plan', 96, [], [
                ['Share the quarterly environmental monitoring report with the district', 30, 'low'],
            ]],
            ['Grievance mechanism awareness, Shelui', 'community_meeting', -35, 'unplanned', 120, [
                ['People do not know the toll-free number exists', 'medium', 'community_relations_engagement'],
            ], [
                ['Print and post the grievance channel poster in all 12 villages', 21, 'medium'],
            ]],
            ['Contractor toolbox talk with Baraka Construction', 'workshop', -28, 'on_plan', 74, [
                ['Workers report PPE is not replaced when damaged', 'high', 'labor_hr_industrial_relations'],
            ], [
                ['Contractor to restock PPE and report weekly for one month', 7, 'high'],
            ]],
            ['Grazing route negotiation, Nyandekwa', 'community_meeting', -21, 'on_plan', 88, [
                ['Cattle route is blocked by the construction laydown area', 'high', 'land_assets_livelihoods'],
            ], [
                ['Open a signed cattle crossing at chainage 214 within one month', 30, 'high'],
            ]],
            ['Door-to-door engagement with vulnerable households', 'household_visit', -14, 'on_plan', 18, [], [
                ['Arrange transport for three residents to the district compensation office', 10, 'medium'],
            ]],
            ['Village assembly, Bulige', 'community_meeting', -6, 'on_plan', 210, [
                ['The borehole promised last year has not been drilled', 'high', 'community_relations_engagement'],
            ], [
                ['Confirm the borehole budget and give the village a written date', 30, 'high'],
            ]],
        ]);

        $cro = $cast->get('cro');
        $commitmentOwners = [$cro->id, $cast->get('hse')->id, $cast->get('pm')->id];
        $count = 0;

        foreach ($programme as $index => [$topic, $method, $daysAgo, $variance, $attendance, $concerns, $commitments]) {
            $village = $villages[$index % count($villages)];
            $heldAt = now()->subDays(abs($daysAgo));

            $plan = null;

            if ($variance !== 'unplanned') {
                $targetDate = $variance === 'late' ? $heldAt->copy()->subDays(9) : $heldAt->copy();

                $plan = $this->engagements->createPlan($project, [
                    'title' => $topic,
                    'project_phase' => 'construction',
                    'stakeholder_id' => $register[$index % count($register)]->id,
                    'purpose' => 'Planned under the Stakeholder Engagement Plan for the '.$village->name.' corridor section.',
                    'method' => $method,
                    'target_date' => $targetDate->toDateString(),
                    'location_id' => $village->id,
                    'location_text' => $village->path,
                    'vulnerable_group_accommodation' => $index % 3 === 0,
                    'accommodation_notes' => $index % 3 === 0 ? 'Swahili interpretation and seating for elderly attendees.' : null,
                    'fpic_required' => $method === 'community_meeting' && $index % 4 === 0,
                    'grievance_channel_available' => true,
                    'owner_id' => $cro->id,
                    'responsible_team' => 'Community relations',
                    'priority' => $index % 3 === 0 ? 'high' : 'medium',
                    'status' => 'planned',
                ]);
            }

            $female = (int) round($attendance * (rand(35, 55) / 100));

            $this->engagements->logEngagement($project, [
                'engagement_plan_id' => $plan?->id,
                'topic' => $topic,
                'project_phase' => 'construction',
                'location_id' => $village->id,
                'location_text' => $village->path,
                'held_at' => $heldAt->setTime(10, 0),
                'ended_at' => $heldAt->setTime(13, 30),
                'method' => $method,
                'venue' => $village->name.' village office',
                'organised_by' => $organiser.' (Community Relations)',
                'facilitator_id' => $cro->id,
                'aim' => 'Disclose the current works programme, hear concerns and confirm outstanding commitments.',
                'discussion_points' => "Works programme for the next quarter was presented.\n"
                    ."The grievance mechanism and toll-free number were explained again.\n"
                    ."Outstanding commitments from the previous meeting were read out and their status confirmed.\n"
                    .'Attendees raised the matters recorded below as concerns.',
                'outcomes' => 'Concerns recorded, commitments made and owners assigned in the meeting.',
                'attendance_total' => $attendance,
                'attendance_female' => $female,
                'attendance_male' => $attendance - $female,
                'attendance_youth' => (int) round($attendance * 0.28),
                'attendance_elderly' => (int) round($attendance * 0.12),
                'attendance_disability' => (int) round($attendance * 0.04),
                'attendance_vulnerable' => (int) round($attendance * 0.18),
                'vulnerable_groups_present' => true,
                'vulnerable_groups' => ['elderly', 'female_headed_household'],
                'status' => 'logged',
                'captured_at' => $heldAt,
                'stakeholder_ids' => collect($register)->random(min(4, count($register)))->pluck('id')->all(),
                'participants' => [
                    ['name' => 'Village chairperson', 'category' => 'community', 'position' => 'Chairperson', 'signed_attendance' => true, 'demographics' => ['gender' => 'male', 'age_band' => '36_60']],
                    ['name' => 'Ward executive officer', 'category' => 'government', 'position' => 'WEO', 'signed_attendance' => true, 'demographics' => ['gender' => 'male', 'age_band' => '36_60']],
                    ['name' => 'Women\'s group representative', 'category' => 'cso', 'signed_attendance' => true, 'is_vulnerable' => false, 'demographics' => ['gender' => 'female', 'age_band' => '18_35']],
                ],
                'concerns' => collect($concerns)->map(fn ($concern) => [
                    'title' => $concern[0],
                    'description' => $concern[0].'. Raised in the meeting and recorded in the minutes.',
                    'severity_hint' => $concern[1],
                    'grievance_category_id' => $categories[$concern[2]]->id ?? null,
                    'raised_by' => 'Meeting attendee',
                    'raised_on' => $heldAt->toDateString(),
                ])->all(),
                'commitments' => collect($commitments)->map(fn ($commitment, $i) => [
                    'commitment_text' => $commitment[0],
                    'owner_id' => $commitmentOwners[$i % 3],
                    'due_date' => $heldAt->copy()->addDays($commitment[1])->toDateString(),
                    'risk_level' => $commitment[2],
                    'stakeholder_ids' => [$register[$index % count($register)]->id],
                ])->all(),
            ]);

            $count++;
        }

        // A plan whose window has passed with nothing logged — a real "missed".
        $this->engagements->createPlan($project, [
            'title' => $this->fictional('Resettlement committee meeting, Kiomboi'),
            'project_phase' => 'construction',
            'purpose' => 'Agree the replacement land allocation for four households.',
            'method' => 'community_meeting',
            'target_date' => now()->subDays(11)->toDateString(),
            'location_id' => $villages[0]->id,
            'owner_id' => $cro->id,
            'priority' => 'high',
            'status' => 'planned',
        ]);

        // And several genuinely upcoming ones, so the calendar is not empty.
        $offsets = $this->sample ? self::SAMPLE['upcoming_plans'] : [4, 9, 16, 23, 38];

        foreach ($offsets as $offset) {
            $village = $villages[$offset % count($villages)];

            $this->engagements->createPlan($project, [
                'title' => 'Quarterly disclosure meeting, '.$village->name,
                'project_phase' => 'construction',
                'purpose' => 'Disclose the works programme for the coming quarter and review commitments.',
                'method' => 'community_meeting',
                'target_date' => now()->addDays($offset)->toDateString(),
                'location_id' => $village->id,
                'location_text' => $village->path,
                'vulnerable_group_accommodation' => true,
                'accommodation_notes' => 'Swahili interpretation; meeting held at ground level for wheelchair access.',
                'grievance_channel_available' => true,
                'owner_id' => $cro->id,
                'responsible_team' => 'Community relations',
                'priority' => $offset < 10 ? 'high' : 'medium',
                'status' => 'planned',
            ]);
        }

        return $count;
    }

    /**
     * @param  array<int,Location>  $villages
     * @param  array<string,GrievanceCategory>  $categories
     * @param  array<int,Stakeholder>  $register
     */
    private function seedGrievances(Project $project, DemoCast $cast, array $villages, array $categories, array $register): int
    {
        $cases = $this->pick('grievances', [
            // [days ago, channel, category, subcategory, severity, confidentiality, title, description, desired, lifecycle]
            [-88, 'voice', 'environmental_health_safety', 'dust_air_quality', 3, 'normal',
                'Dust from haulage trucks is covering houses in Buswelu',
                'The caller said trucks pass every few minutes from early morning and the dust settles on houses, food and washing. Children in the household have been coughing. He asked that the road be watered.',
                'Water the road, or move the haulage route away from the houses.', 'closed'],
            [-76, 'in_person', 'land_assets_livelihoods', 'compensation_delay', 3, 'normal',
                'Compensation for maize crop cleared in March has not been paid',
                'The complainant\'s maize was cleared during right-of-way preparation in March. She was told payment would arrive within 60 days. It is now five months. She has no other income this season.',
                'Pay the assessed amount, and explain why it was late.', 'closed'],
            [-64, 'whatsapp', 'labor_hr_industrial_relations', 'wages', 3, 'confidential',
                'Overtime hours worked in June were not paid',
                'A contractor employee reports that overtime worked on the tower foundations in June was recorded on the site sheet but not paid. Several colleagues are affected but are afraid to complain.',
                'Pay the outstanding overtime and confirm in writing how hours are recorded.', 'resolved'],
            [-58, 'sms', 'community_relations_engagement', 'unfulfilled_promise', 2, 'normal',
                'The borehole promised at the 2025 assembly has not been drilled',
                'The village was told a borehole would be drilled as part of the community benefit package. Nothing has happened and the village leadership cannot get an answer.',
                'Give the village a written date, or explain honestly if it is not happening.', 'under_investigation'],
            [-46, 'voice', 'human_rights_workplace_conduct', 'sea_sh', 5, 'confidential',
                'Restricted case: handled by the named handling group',
                'Details of this case are restricted to the designated handling group under the project\'s SEA/SH procedure.',
                'Restricted.', 'under_investigation'],
            [-41, 'leader', 'cultural_heritage', 'graves', 4, 'normal',
                'Grading work came within metres of a family burial site',
                'The village chairman reports that clearing near tower 142 came within a few metres of a family burial site. Work stopped when elders intervened. The family wants assurance it will not resume without agreement.',
                'Stop work at that location until the family and elders agree a boundary.', 'action_pending'],
            [-33, 'in_person', 'environmental_health_safety', 'water_quality', 4, 'normal',
                'Borehole water at Ndago turned cloudy after substation excavation',
                'The water users association reports that both boreholes serving Ndago turned cloudy in the week after excavation began at the substation site. About 900 people rely on them.',
                'Test the water, and provide an alternative supply until it is confirmed safe.', 'under_investigation'],
            [-27, 'web', 'local_employment_economic', 'hiring_process', 2, 'normal',
                'Local young people say hiring is going to people from outside the district',
                'The youth group reports that recent hiring for the tower crews went almost entirely to people from outside the district, despite the local content commitment made in the disclosure meeting.',
                'Publish the hiring numbers by district and hold a recruitment day locally.', 'assigned'],
            [-19, 'voice', 'environmental_health_safety', 'road_safety', 4, 'normal',
                'A project vehicle nearly hit a child near Msingi Primary School',
                'A caller reports that a project pickup passed the school at speed during break time and nearly struck a child. Several parents witnessed it. They want speed control before someone is killed.',
                'Enforce a speed limit and put humps and signs outside the school.', 'acknowledged'],
            [-12, 'suggestion_box', 'community_relations_engagement', 'contractor_behaviour', 2, 'anonymous',
                'Contractor workers are drinking in the village at night',
                'A note left in the suggestion box at the village office says contractor workers drink in the village at night and are disturbing residents. No contact details were left.',
                'Enforce the contractor code of conduct.', 'classified'],
            [-8, 'sms', 'land_assets_livelihoods', 'access_restriction', 3, 'normal',
                'Access road to the Kiomboi shops has been closed without notice',
                'The shopkeeper reports the access road was closed for tower works without notice. Customers cannot reach the shops and takings have halved.',
                'Reopen the road, or provide a marked alternative route.', 'new'],
            [-5, 'whatsapp', 'labor_hr_industrial_relations', 'ppe', 3, 'confidential',
                'PPE is not being replaced when it is damaged',
                'A worker reports that damaged gloves and boots are not being replaced, and workers are told to buy their own. He asked that his name not be shared with the contractor.',
                'Restock PPE and confirm the replacement procedure to workers.', 'assigned'],
            [-3, 'voice', 'ethics_compliance', 'bribery', 4, 'confidential',
                'Restricted case: handled by the named handling group',
                'Details of this case are restricted to the designated handling group under the project\'s ethics procedure.',
                'Restricted.', 'new'],
            [-2, 'in_person', 'environmental_health_safety', 'noise_vibration', 2, 'normal',
                'Night-time generator noise at the construction camp',
                'Residents next to the camp report the generator runs through the night and they cannot sleep. They asked whether it can be moved or fitted with a silencer.',
                'Move the generator or fit a silencer.', 'new'],
            [-1, 'web', 'land_assets_livelihoods', 'crop_damage', 2, 'normal',
                'Beehives were destroyed when the right of way was cleared',
                'The beekeepers group reports that eleven hives were destroyed during clearing. They were not told clearing was due to start and had no chance to move them.',
                'Compensate for the hives and give notice before any further clearing.', 'new'],
        ]);

        $grievanceOfficer = $cast->get('grievance');

        foreach ($cases as $index => [$daysAgo, $channel, $categoryKey, $subKey, $severity, $confidentiality, $title, $description, $desired, $lifecycle]) {
            $receivedAt = now()->subDays(abs($daysAgo));
            $village = $villages[$index % count($villages)];
            $category = $categories[$categoryKey] ?? null;
            $subcategory = $categories[$categoryKey.'.'.$subKey] ?? null;
            $stakeholder = $register[$index % count($register)];

            Auth::setUser($grievanceOfficer);

            $identity = $confidentiality === 'anonymous' ? [] : [
                'complainant_name' => $stakeholder->name,
                'complainant_phone' => $stakeholder->phone,
                'complainant_type' => 'community_member',
                'stakeholder_id' => $stakeholder->id,
            ];

            $grievance = $this->intake->intake($project, array_merge($identity, [
                'channel' => $channel,
                'received_at' => $receivedAt,
                'occurred_at' => $receivedAt->copy()->subDays(rand(0, 4)),
                'confidentiality' => $confidentiality,
                'complainant_language' => $index % 4 === 0 ? 'en' : 'sw',
                'location_id' => $village->id,
                'location_text' => $village->path,
                'category_id' => $category?->id,
                'subcategory_id' => $subcategory?->id,
                'severity' => $severity,
                'title' => $title,
                'description' => $description,
                'desired_resolution' => $desired,
                'demographics' => [
                    'gender' => $index % 2 === 0 ? 'female' : 'male',
                    'age_band' => ['18_35', '36_60', 'over_60'][$index % 3],
                    'disability' => $index % 9 === 0 ? 'yes' : 'no',
                    'employment' => ['farmer', 'self_employed', 'contractor', 'unemployed'][$index % 4],
                    'language' => $index % 4 === 0 ? 'en' : 'sw',
                ],
                'captured_at' => $receivedAt,
            ]));

            $this->advanceLifecycle($cast, $grievance, $lifecycle, $receivedAt);
        }

        // A reopened case: the complainant did not accept the resolution.
        $reopened = Grievance::acrossTenants()->where('project_id', $project->id)
            ->where('status', 'closed')->orderBy('id')->first();

        if ($reopened) {
            Auth::setUser($grievanceOfficer);
            $this->grievances->reopen($reopened, 'The complainant says the road was watered for three days and then stopped. The dust is back.');
            $this->grievances->assign($reopened, $cast->get('hse')->id, 'HSE', 'Reassigned to HSE for a durable fix rather than a temporary one.');
        }

        return count($cases);
    }

    private function advanceLifecycle(DemoCast $cast, Grievance $grievance, string $target, Carbon $receivedAt): void
    {
        $order = ['new', 'classified', 'assigned', 'acknowledged', 'under_investigation', 'action_pending', 'resolved', 'closed'];
        $targetIndex = array_search($target, $order, true);

        if ($targetIndex === false || $targetIndex === 0) {
            return;
        }

        $assignees = [
            'environmental_health_safety' => 'hse',
            'labor_hr_industrial_relations' => 'hr',
            'human_rights_workplace_conduct' => 'hr',
            'ethics_compliance' => 'grievance',
        ];

        $categoryKey = $grievance->category?->key;
        $assignee = $cast->get($assignees[$categoryKey] ?? 'grievance');
        $service = $this->grievances;

        $service->classify($grievance, [
            'category_id' => $grievance->category_id,
            'subcategory_id' => $grievance->subcategory_id,
            'severity' => $grievance->severity,
        ]);

        if ($targetIndex < 2) {
            return;
        }

        $service->assign($grievance, $assignee->id, null, 'Assigned by the duty grievance officer.');

        if ($targetIndex < 3) {
            return;
        }

        Auth::setUser($assignee);

        if ($grievance->acknowledgement_possible) {
            $service->acknowledge($grievance, $grievance->channel === 'in_person' ? 'in_person' : 'sms');
        }

        if ($targetIndex < 4) {
            return;
        }

        $service->startInvestigation($grievance, 'Site visit scheduled and the complainant contacted.');
        $grievance->followUps()->create([
            'organisation_id' => $grievance->organisation_id,
            'project_id' => $grievance->project_id,
            'type' => 'site_visit',
            'body' => 'Visited the location with the ward executive officer. Photographed the conditions described and spoke with three neighbouring households, who confirmed the account.',
            'occurred_on' => $receivedAt->copy()->addDays(3)->toDateString(),
            'resolution_cycle' => 1,
            'created_by' => $assignee->id,
            'captured_at' => $receivedAt->copy()->addDays(3),
            'synced_at' => now(),
        ]);

        if ($targetIndex < 5) {
            return;
        }

        $service->recordInvestigation($grievance, [
            'investigation_summary' => 'The account was verified on site. The immediate cause was identified and the responsible contractor was briefed.',
            'investigation_findings' => 'The complaint is substantiated. The control that should have prevented it was not being applied consistently.',
            'corrective_action' => 'Reinstate the control, brief the crew, and check weekly for one month.',
            'corrective_action_owner_id' => $assignee->id,
            'corrective_action_due' => $receivedAt->copy()->addDays(21)->toDateString(),
            'completed' => true,
        ]);

        if ($targetIndex < 6) {
            return;
        }

        $service->resolve(
            $grievance,
            'The corrective action was carried out and confirmed on site. The complainant was visited, shown what had changed, and asked whether they were satisfied.',
            'Control reinstated and verified in place.'
        );

        if ($targetIndex < 7) {
            return;
        }

        $service->recordComplainantResponse($grievance, 'accepted', 'The complainant confirmed they are satisfied with the outcome.');
        $service->close($grievance, 'Closed with the complainant\'s agreement. Corrective action verified.');
    }

    /**
     * @param  array<int,Location>  $villages
     * @param  array<int,Stakeholder>  $register
     */
    private function seedStandaloneCommitments(Project $project, DemoCast $cast, array $villages, array $register): int
    {
        Auth::setUser($cast->get('pm'));

        $definitions = $this->pick('commitments', [
            ['Build two additional classrooms at Msingi Primary School as part of the community benefit package', 120, 'high', 'open'],
            ['Employ at least 60% of unskilled labour from the four host districts across the construction period', -25, 'high', 'overdue'],
            ['Rehabilitate the Kiomboi–Shelui access road on completion of tower works', 90, 'medium', 'open'],
            ['Deliver a quarterly environmental monitoring report to each district council', -6, 'medium', 'overdue'],
            ['Provide replacement grazing access at three points along the corridor', 45, 'high', 'in_progress'],
            ['Run a skills training programme for 40 young people from the host wards', 200, 'medium', 'open'],
            ['Fence and mark the substation perimeter before energisation', -40, 'high', 'fulfilled'],
            ['Restore all borrow pits to agreed condition before demobilisation', 365, 'medium', 'open'],
        ]);

        $owners = [$cast->get('pm')->id, $cast->get('cro')->id, $cast->get('hse')->id];

        foreach ($definitions as $index => [$text, $dueOffset, $risk, $status]) {
            $commitment = $this->commitments->create($project, [
                'commitment_text' => $text,
                'source_type' => 'manual',
                'source_date' => now()->subDays(rand(40, 200))->toDateString(),
                'owner_id' => $owners[$index % 3],
                'owner_team' => ['Project management', 'Community relations', 'HSE'][$index % 3],
                'due_date' => now()->addDays($dueOffset)->toDateString(),
                'risk_level' => $risk,
                'priority' => $risk,
                'location_id' => $villages[$index % count($villages)]->id,
                'stakeholder_ids' => [$register[$index % count($register)]->id],
                'status' => in_array($status, ['open', 'in_progress'], true) ? $status : 'open',
            ]);

            if ($status === 'overdue') {
                $this->commitments->changeStatus($commitment, 'overdue');
            }

            if ($status === 'fulfilled') {
                $this->commitments->changeStatus($commitment, 'fulfilled', [
                    'completed_on' => now()->subDays(abs($dueOffset) - 5)->toDateString(),
                    'evidence_notes' => 'Completion photographs and the contractor\'s sign-off sheet are attached.',
                ]);

                // One fulfilled but unverified, so the register shows the gap.
                if ($index % 2 === 0) {
                    Auth::setUser($cast->get('cro'));
                    $this->commitments->verify($commitment->fresh(), 'verified', 'Verified on site with the village chairperson present.');
                    Auth::setUser($cast->get('pm'));
                }
            }
        }

        return count($definitions);
    }

    // ------------------------------------------------------------------ PAPs

    /**
     * A resettlement register in mid-flight: PAPs across four villages,
     * surveyed, inventoried and valued, with payments in every state,
     * households relocated, a livelihood programme under way and three
     * consultation meetings. Every PAP is created through PapService, so each
     * one lands in the stakeholder register too.
     *
     * @return array<string,int>
     */
    public function paps(Project $project, DemoCast $cast, bool $sample = false): array
    {
        $this->sample = $sample;

        $villages = Location::where('organisation_id', $project->organisation_id)
            ->where('level', 'village')->orderBy('id')->limit(4)->get()->values();

        if ($villages->isEmpty()) {
            return [];
        }

        return $this->as($cast->get('cro'), function () use ($project, $cast, $villages) {
            $created = $this->seedPaps($project, $villages);
            $this->seedPapRecords($created);
            $this->seedPapConsultations($project, $villages);
            $this->seedPapGrievances($project, $cast, $created);

            return ['paps' => count($created)];
        });
    }

    /**
     * @param  Collection<int,Location>  $villages
     * @return array<int,Pap>
     */
    private function seedPaps(Project $project, $villages): array
    {
        // [name, gender, dob, marital, vulnerability, village index, kitongoji, joins household of index]
        $people = $this->pick('paps', [
            ['Neema Charles Mwita', 'female', '1979-03-14', 'widowed', 'female_headed_household', 0, 'Mwembeni', null],
            ['Juma Charles Mwita', 'male', '2001-08-02', 'single', 'none', 0, 'Mwembeni', 0],
            ['Mzee Rashid Kiwelu', 'male', '1946-11-20', 'married', 'elderly_person', 0, 'Kati', null],
            ['Amina Juma Nkya', 'female', '1988-06-09', 'married', 'person_with_disability', 1, 'Mlimani', null],
            ['Hamisi Selemani Mollel', 'male', '1975-01-30', 'married', 'none', 1, 'Sokoni', null],
            ['Rehema Hamisi Mollel', 'female', '1980-04-18', 'married', 'none', 1, 'Sokoni', 4],
            ['Daudi Peter Masanja', 'male', '1968-09-05', 'divorced', 'chronically_ill', 2, 'Bondeni', null],
            ['Fatuma Ally Ngoyai', 'female', '1992-12-11', 'separated', 'extremely_poor_household', 2, 'Bondeni', null],
            ['Elias John Shija', 'male', '1983-02-27', 'married', 'none', 2, 'Majengo', null],
            ['Mariamu Said Kweka', 'female', '2004-07-16', 'single', 'orphan_headed_household', 3, 'Kilimahewa', null],
            ['Paulo Mathias Lyimo', 'male', '1971-05-03', 'married', 'none', 3, 'Kilimahewa', null],
            ['Grace Joseph Mwakyusa', 'female', '1990-10-22', 'married', 'none', 3, 'Shuleni', null],
        ]);

        $created = [];

        foreach ($people as $index => [$name, $gender, $dob, $marital, $vulnerability, $villageIndex, $kitongoji, $joins]) {
            $village = $villages[$villageIndex % $villages->count()];

            $created[$index] = $this->paps->create($project, [
                'full_name' => $name,
                'national_id' => sprintf('%s-%05d-%05d-%02d', str_replace('-', '', $dob), 41100 + $index, $index + 1, 20 + $index),
                'gender' => $gender,
                'date_of_birth' => $dob,
                'marital_status' => $marital,
                'phone' => $this->sample ? $this->phone() : sprintf('+255 7%02d %03d %03d', 54 + $index, 120 + $index * 7, 300 + $index * 11),
                'alternative_contact' => $index % 3 === 0
                    ? ($this->sample ? $this->phone() : sprintf('+255 6%02d %03d %03d', 10 + $index, 400 + $index, 500 + $index))
                    : null,
                'location_id' => $village->id,
                'kitongoji' => $kitongoji,
                'physical_address' => "Kitongoji cha {$kitongoji}, near the line corridor",
                'latitude' => $village->latitude ? (float) $village->latitude + $index * 0.001 : -4.30 - $index * 0.01,
                'longitude' => $village->longitude ? (float) $village->longitude + $index * 0.001 : 34.70 + $index * 0.01,
                'next_of_kin' => $index % 2 === 0 ? 'Spouse or eldest child' : 'Sibling',
                'next_of_kin_contact' => $this->sample ? $this->phone() : sprintf('+255 78%d %03d %03d', $index % 10, 200 + $index, 600 + $index),
                'vulnerability_category' => $vulnerability,
                'household_id' => $joins !== null && isset($created[$joins]) ? $created[$joins]->household_id : null,
                'remarks' => $vulnerability !== 'none' ? 'Vulnerable PAP: prioritise for assistance and door-to-door engagement.' : null,
                'captured_at' => now()->subMonths(8)->addDays($index * 3),
            ]);
        }

        return $created;
    }

    /** @param array<int,Pap> $created */
    private function seedPapRecords(array $created): void
    {
        $records = $this->papRecords;
        $paymentStatuses = ['paid', 'paid', 'partially_paid', 'pending', 'paid', 'on_hold', 'partially_paid', 'pending', 'disputed', 'paid', 'pending', 'partially_paid'];

        foreach ($created as $index => $pap) {
            $plot = sprintf('%s/%03d', strtoupper(substr($pap->village ?? 'NBT', 0, 3)), 100 + $index * 7);
            $total = round(0.8 + ($index % 5) * 0.6, 2);
            $affected = round($total * (0.15 + ($index % 4) * 0.2), 2);

            if ($index !== 1 && $index !== 5) { // dependants in a household share the head's plot
                $records->create($pap, 'household-surveys', [
                    'survey_date' => now()->subMonths(7)->toDateString(),
                    'household_head' => $pap->full_name,
                    'household_size' => 3 + $index % 6,
                    'number_of_adults' => 2 + $index % 2,
                    'number_of_children' => 1 + $index % 5,
                    'education_level' => ['primary', 'secondary', 'none', 'primary', 'vocational'][$index % 5],
                    'primary_occupation' => ['Farmer', 'Livestock keeper', 'Petty trader', 'Farmer'][$index % 4],
                    'secondary_occupation' => $index % 2 ? 'Casual labour' : null,
                    'monthly_household_income' => 180000 + $index * 35000,
                    'main_income_source' => ['Maize and beans', 'Cattle', 'Kiosk', 'Rice'][$index % 4],
                    'asset_ownership_score' => 3 + $index % 7,
                    'poverty_category' => $pap->isVulnerable() ? 'poor' : 'vulnerable_non_poor',
                    'livestock_owned' => $index % 3 === 0 ? '4 cattle, 10 goats, chickens' : '6 chickens',
                    'access_to_electricity' => $index % 4 === 0 ? 'solar' : 'none',
                    'access_to_water' => ['borehole', 'unprotected_source', 'protected_well'][$index % 3],
                    'access_to_sanitation' => ['traditional_pit', 'improved_pit'][$index % 2],
                ]);

                $records->create($pap, 'land', [
                    'plot_number' => $plot,
                    'village' => $pap->village,
                    'land_use' => ['agricultural', 'residential', 'mixed', 'agricultural'][$index % 4],
                    'ownership_type' => ['customary_occupant', 'owner', 'customary_occupant', 'tenant', 'informal_occupant'][$index % 5],
                    'ccro_number' => $index % 3 === 0 ? sprintf('CCRO/%d/%04d', 2019 + $index % 4, 310 + $index) : null,
                    'total_plot_size_ha' => $total,
                    'affected_area_ha' => $affected,
                    'gps_easting' => 512340 + $index * 145,
                    'gps_northing' => 9523410 + $index * 210,
                    'map_reference' => 'RAP strip map sheet '.(3 + intdiv($index, 4)),
                    'land_status' => $paymentStatuses[$index] === 'paid' ? 'compensated' : 'valued',
                ]);

                $records->create($pap, 'assets', [
                    'asset_category' => $index % 3 === 0 ? 'residential_structure' : 'fence',
                    'asset_type' => $index % 3 === 0 ? 'Burnt-brick house, iron sheet roof' : 'Live fence (euphorbia)',
                    'length' => $index % 3 === 0 ? 9 : 40 + $index * 3,
                    'width' => $index % 3 === 0 ? 7 : 1,
                    'quantity' => 1,
                    'unit' => $index % 3 === 0 ? 'm2' : 'm',
                    'condition' => ['good', 'fair', 'poor'][$index % 3],
                    'affected_status' => $index % 3 === 0 ? 'fully_affected' : 'partially_affected',
                    'photo_reference' => sprintf('IMG_%04d.jpg', 2100 + $index),
                ]);

                $records->create($pap, 'crops', [
                    'plot_number' => $plot,
                    'crop_type' => 'Mango',
                    'number_of_plants' => 4 + $index,
                    'tree_species' => 'Mangifera indica',
                    'tree_age' => 5 + $index % 8,
                    'productive_status' => 'mature_productive',
                    'rate_basis' => 'per_plant',
                    'unit_rate' => 45000,
                ]);
                $records->create($pap, 'crops', [
                    'plot_number' => $plot,
                    'crop_type' => 'Maize',
                    'area_cultivated' => $affected,
                    'productive_status' => 'immature',
                    'rate_basis' => 'per_hectare',
                    'unit_rate' => 1200000,
                ]);
            }

            $landValue = round($affected * 3500000, -3);
            $structure = $index % 3 === 0 ? 14500000 : 900000 + $index * 50000;
            $crops = (4 + $index) * 45000 + round($affected * 1200000, -3);

            $valuation = $records->create($pap, 'valuations', [
                'valuation_date' => now()->subMonths(5)->toDateString(),
                'valuer_name' => 'Chief Government Valuer\'s office (Mwanza zone)',
                'valuation_report_number' => $this->fictional(sprintf('CGV/NBT/%03d', 40 + $index)),
                'land_value' => $landValue,
                'structure_value' => $structure,
                'crop_value' => $crops,
                'tree_value' => 0,
                'disturbance_allowance' => round(($landValue + $structure) * 0.08, -3),
                'transport_allowance' => $index % 3 === 0 ? 450000 : 0,
                'accommodation_allowance' => $index % 3 === 0 ? 1440000 : 0,
                'loss_of_profit_allowance' => $index === 7 ? 600000 : 0,
                'approval_status' => $index === 10 ? 'submitted' : 'approved',
                'approval_date' => $index === 10 ? null : now()->subMonths(4)->toDateString(),
            ]);

            $records->create($pap, 'entitlements', [
                'entitlement_type' => 'cash_compensation',
                'description' => 'Cash compensation per the approved valuation.',
                'quantity' => 1,
                'unit_rate' => (float) $valuation->total_compensation_value,
                'approval_date' => $index === 10 ? null : now()->subMonths(4)->toDateString(),
                'entitlement_status' => $index === 10 ? 'proposed' : 'approved',
            ]);

            if ($index === 10) {
                continue; // valuation not yet approved: no payment yet
            }

            $approved = (float) $valuation->total_compensation_value;
            $status = $paymentStatuses[$index];
            $paid = match ($status) {
                'paid' => $approved,
                'partially_paid' => round($approved * 0.5, -3),
                default => 0,
            };

            $records->create($pap, 'payments', [
                'compensation_agreement_number' => $this->fictional(sprintf('NBT/CA/%04d', 200 + $index)),
                'bank_name' => $this->fictional($index % 2 ? 'CRDB Bank' : 'NMB Bank'),
                'bank_account_number' => $index % 4 === 3 ? null : sprintf('0150%08d', 3301200 + $index * 17),
                'mobile_money_number' => $index % 4 === 3 ? $pap->phone : null,
                'amount_approved' => $approved,
                'amount_paid' => $paid,
                'payment_date' => $paid > 0 ? now()->subMonths(2)->addDays($index)->toDateString() : null,
                'payment_method' => $index % 4 === 3 ? 'mobile_money' : 'bank_transfer',
                'receipt_number' => $paid > 0 ? sprintf('RCT-%05d', 8800 + $index) : null,
                'acknowledgement_signed' => $paid > 0,
                'witness_name' => $paid > 0 ? 'Village Executive Officer' : null,
                'payment_status' => in_array($status, ['on_hold', 'disputed'], true) ? $status : null,
                'comments' => match ($status) {
                    'on_hold' => 'Held pending probate: the registered owner is deceased.',
                    'disputed' => 'PAP disputes the plot boundary; referred to the grievance committee.',
                    default => null,
                },
            ]);
        }

        // Two households relocated, one in transit.
        foreach ([[0, 'relocated'], [2, 'relocated'], [3, 'house_handed_over']] as [$index, $status]) {
            if (! isset($created[$index])) {
                continue;
            }

            $records->create($created[$index], 'relocations', [
                'relocation_required' => true,
                'replacement_plot_number' => sprintf('RSA/%03d', 10 + $index),
                'replacement_house_number' => sprintf('H-%02d', 3 + $index),
                'house_handover_date' => now()->subMonths(3)->toDateString(),
                'moving_date' => $status === 'relocated' ? now()->subMonths(2)->toDateString() : null,
                'moving_assistance_provided' => $status === 'relocated',
                'transit_assistance_provided' => $status === 'relocated',
                'relocation_status' => $status,
            ]);
        }

        // Livelihood restoration for the most affected and the vulnerable.
        foreach ([[0, 'Poultry keeping', 'completed', 'yes'], [3, 'Tailoring and small business', 'active', 'partially'],
            [6, 'Improved maize agronomy', 'needs_follow_up', 'no'], [7, 'Village savings group (VSLA)', 'active', 'partially'],
            [9, 'Beekeeping', 'not_started', null]] as [$index, $programme, $status, $restored]) {
            if (! isset($created[$index])) {
                continue;
            }

            $enrolled = now()->subMonths(6);
            $visits = $status === 'not_started' ? [] : [
                ['visit_date' => $enrolled->copy()->addMonths(1)->toDateString(), 'visited_by' => 'Livelihood officer', 'findings' => 'Inputs delivered; training attended.'],
                ['visit_date' => $enrolled->copy()->addMonths(3)->toDateString(), 'visited_by' => 'Livelihood officer', 'findings' => 'Activity running; first sales recorded.'],
            ];

            if ($status === 'completed') {
                $visits[] = ['visit_date' => $enrolled->copy()->addMonths(5)->toDateString(), 'visited_by' => 'M&E officer', 'findings' => 'Income at or above pre-project baseline.'];
            }

            $records->create($created[$index], 'livelihoods', [
                'income_source_lost' => 'Crop sales from the affected plot',
                'livelihood_program' => $programme,
                'training_provided' => 'Five-day practical training with a district extension officer',
                'grant_amount' => 750000,
                'business_startup_support' => $index === 3 ? 'Sewing machine and start-up fabric' : null,
                'agricultural_support' => $index === 6 ? 'Improved seed and fertiliser for two seasons' : null,
                'date_enrolled' => $enrolled->toDateString(),
                'date_completed' => $status === 'completed' ? now()->subMonth()->toDateString() : null,
                'monitoring_visits' => $visits,
                'income_restored' => $restored,
                'programme_status' => $status,
            ]);
        }

        foreach ([0, 2, 4, 6, 8] as $index) {
            if (! isset($created[$index])) {
                continue;
            }

            $records->create($created[$index], 'documents', [
                'document_type' => 'nida_card',
                'document_number' => $created[$index]->national_id,
                'issue_date' => now()->subYears(4)->toDateString(),
                'digital_file_name' => sprintf('%s-nida.pdf', $created[$index]->reference),
                'storage_location' => 'RAP office, cabinet 2',
                'verified_by' => 'Community relations officer',
                'verification_date' => now()->subMonths(7)->toDateString(),
            ]);
            $records->create($created[$index], 'documents', [
                'document_type' => 'compensation_agreement',
                'document_number' => $this->fictional(sprintf('NBT/CA/%04d', 200 + $index)),
                'issue_date' => now()->subMonths(4)->toDateString(),
                'digital_file_name' => sprintf('%s-agreement.pdf', $created[$index]->reference),
                'storage_location' => 'RAP office, cabinet 2',
            ]);
        }
    }

    /** @param Collection<int,Location> $villages */
    private function seedPapConsultations(Project $project, $villages): void
    {
        foreach ([
            [-150, 0, 'village_assembly', 'Project disclosure and cut-off date for eligibility', 64, 58, 12,
                'Fear that people arriving after the census will be excluded; questions on the cut-off date.',
                'Cut-off date announced and posted at the village office; census list to be displayed for 14 days.'],
            [-110, 1, 'valuation_disclosure', 'Disclosure of valuation rates for land, crops and structures', 41, 47, 9,
                'Mango tree rates considered too low; women asked to be named on payment for jointly owned land.',
                'Valuer to explain the rate basis; spousal consent and joint accounts offered for household land.'],
            [-40, 2, 'focus_group', 'Livelihood restoration options for vulnerable households', 8, 19, 15,
                'Elderly and disabled PAPs cannot travel to the district for training.',
                'Training to be held in the village; transport allowance for those who must travel.'],
        ] as [$days, $villageIndex, $type, $topic, $male, $female, $vulnerable, $concerns, $actions]) {
            $village = $villages[$villageIndex % $villages->count()];

            $this->papConsultations->create($project, [
                'consultation_date' => now()->addDays($days)->toDateString(),
                'location_id' => $village->id,
                'meeting_type' => $type,
                'topic_discussed' => $topic,
                'facilitator' => 'Community relations team with the Village Executive Officer',
                'number_male' => $male,
                'number_female' => $female,
                'number_vulnerable' => $vulnerable,
                'attendance_sheet_reference' => sprintf('ATT/%s/%s', strtoupper(substr($village->name, 0, 3)), now()->addDays($days)->format('Ymd')),
                'key_concerns_raised' => $concerns,
                'actions_agreed' => $actions,
            ]);
        }
    }

    /** Part K lives in the grievance module: a few cases linked to PAPs. @param array<int,Pap> $created */
    private function seedPapGrievances(Project $project, DemoCast $cast, array $created): void
    {
        $categories = GrievanceCategory::where('project_id', $project->id)->get()->keyBy('key');
        $category = $categories['land_assets_livelihoods'] ?? $categories->first();

        Auth::setUser($cast->get('grievance'));

        foreach ([
            [8, 'compensation_amount', 'Boundary of plot disputed after valuation', 'The PAP says the surveyed boundary excludes part of the field his family has farmed for twenty years, so the compensation is too low.'],
            [3, 'compensation_delay', 'Balance of compensation not yet received', 'Half of the compensation was paid in August. The PAP was told the balance would follow within a month and has heard nothing since.'],
            [5, 'compensation_delay', 'Payment on hold without explanation', 'The family was told payment is on hold but nobody explained why or what documents are needed to release it.'],
        ] as [$index, $subKey, $title, $description]) {
            if (! isset($created[$index])) {
                continue;
            }

            $pap = $created[$index];

            $this->intake->intake($project, [
                'channel' => 'in_person',
                'received_at' => now()->subDays(20 - $index),
                'confidentiality' => 'normal',
                'complainant_name' => $pap->full_name,
                'complainant_phone' => $pap->phone,
                'complainant_type' => 'community_member',
                'stakeholder_id' => $pap->stakeholder_id,
                'location_id' => $pap->location_id,
                'location_text' => $pap->displayLocation(),
                'category_id' => $category?->id,
                'subcategory_id' => $categories[($category?->key ?? '').'.'.$subKey]->id ?? null,
                'severity' => 3,
                'title' => $title,
                'description' => $description,
                'desired_resolution' => 'Explain the position and pay what is owed.',
            ]);
        }
    }

    // ------------------------------------------------------------------ ESHS

    /**
     * ESHS contractor assurance: contractors and subcontractors configured,
     * and anomalies across every category, risk rating and stage — some
     * overdue, some awaiting verification, some closed on evidence.
     *
     * @param  array<int,string>|null  $contractors  three names (defaults depend on $sample)
     * @param  array<int,string>|null  $subcontractors  two names
     * @return array<string,int>
     */
    public function eshs(Project $project, DemoCast $cast, bool $sample = false, ?array $contractors = null, ?array $subcontractors = null, bool $refreshOnly = false): array
    {
        $this->sample = $sample;
        $contractors ??= $sample ? self::SAMPLE_CONTRACTORS : self::CONTRACTORS;
        $subcontractors ??= $sample ? self::SAMPLE_SUBCONTRACTORS : self::SUBCONTRACTORS;
        $raiser = $cast->get('hse');
        $verifier = $cast->has('pm') ? $cast->get('pm') : $raiser;

        return $this->as($raiser, function () use ($project, $contractors, $subcontractors, $raiser, $verifier, $refreshOnly) {
            $this->eshsConfiguration->updateSettings($project, [
                'contractors' => $contractors,
                'subcontractors' => $subcontractors,
            ]);

            if ($refreshOnly) {
                return ['eshs_anomalies' => 0];
            }

            return ['eshs_anomalies' => $this->seedAnomalies($project, $contractors, $subcontractors, $raiser, $verifier)];
        });
    }

    /**
     * @param  array<int,string>  $contractors
     * @param  array<int,string>  $subcontractors
     */
    private function seedAnomalies(Project $project, array $contractors, array $subcontractors, User $raiser, User $verifier): int
    {
        $locations = Location::where('organisation_id', $project->organisation_id)
            ->where('level', 'village')->limit(12)->pluck('id')->all();

        [$kpc, $lzc, $stg] = $contractors;
        [$haulage, $security] = $subcontractors;

        // [days ago raised, contractor, sub, category, subcategory, type, risk, cause group, cause, target offset (days from today), stage, description, action]
        $rows = $this->pick('eshs', [
            [62, $kpc, null, 'safety', 'Work at Height', 'Non-Conformance', 'critical', 'People', 'Failure to Follow Procedure', -40, 'closed', 'Two linesmen observed working on tower T-112 without a second lanyard attached during conductor stringing.', 'Stand down the crew, re-brief on 100% tie-off, introduce daily harness inspection log.'],
            [55, $lzc, $haulage, 'environment', 'Dust', 'Inspection Finding', 'medium', 'Environmental', 'Dust Control Failure', -30, 'closed', 'Heavy dust plume from haul trucks on the Shinyanga access road passing Ibadakuli primary school; no watering for two days.', 'Water the access road twice daily during dry-season works and post a 30 km/h limit near the school.'],
            [48, $stg, null, 'environment', 'Spill / Leakage', 'Incident', 'high', 'Resources & Equipment', 'Poor Maintenance', -20, 'closed', 'Hydraulic oil leak from the 50 t crane at the Iguguno substation laydown; approx. 20 L on bare soil.', 'Excavate and dispose of contaminated soil via licensed handler; service crane hoses; place drip trays.'],
            [41, $kpc, $security, 'social', 'Community Complaint', 'Community Grievance', 'medium', 'Social', 'Community Communication Failure', -12, 'closed', 'Residents of Mwamala village complained that security guards blocked a footpath to the water point without notice.', 'Re-open the footpath with a marked pedestrian crossing and brief guards on community access.'],
            [36, $lzc, null, 'safety', 'Excavation / Trenching', 'Inspection Finding', 'critical', 'Planning & Management', 'Inadequate Risk Assessment', -15, 'overdue', 'Tower foundation pit at T-087 deeper than 1.5 m with no shoring or edge protection; children seen near the pit after hours.', 'Install hard barricades and shoring; fence and light the pit after hours; revise excavation method statement.'],
            [33, $stg, null, 'health', 'Heat Stress', 'Observation', 'high', 'Health', 'Heat Stress Management Failure', -10, 'overdue', 'No shaded rest area or drinking water at the Manyoni stringing site; afternoon temperatures above 34 °C.', 'Provide shaded rest shelter, cool drinking water and a work–rest schedule for the hottest hours.'],
            [30, $kpc, $haulage, 'safety', 'Traffic Management', 'Near Miss', 'high', 'Planning & Management', 'Inadequate Planning', -7, 'overdue', 'Low-bed carrying a transformer reversed onto the Dodoma–Singida highway without a banksman or flag person.', 'Appoint trained banksmen for every reversing manoeuvre onto public roads and update the traffic management plan.'],
            [27, $lzc, null, 'environment', 'Waste Management', 'Non-Conformance', 'low', 'People', 'Lack of Awareness', -3, 'overdue', 'General waste and cement bags burned in an open pit at the Ilemela camp.', 'Stop open burning; segregate waste and use the licensed collector; toolbox talk for camp staff.'],
            [22, $stg, null, 'social', 'Labor Rights', 'Worker Grievance', 'high', 'Social', 'Labor Rights / Worker Welfare Failure', 5, 'awaiting', 'Casual workers at the Singida yard report wages paid ten days late for two consecutive months.', 'Pay outstanding wages, put payroll on a fixed date and share the pay calendar with workers.'],
            [19, $kpc, null, 'safety', 'PPE Non-Compliance', 'Inspection Finding', 'medium', 'Resources & Equipment', 'PPE Unavailable or Inadequate', 4, 'awaiting', 'Concrete pour crew at T-140 without gloves or eye protection; PPE store reported out of stock.', 'Restock the PPE store, issue gloves and goggles to the crew, set a minimum stock level.'],
            [16, $lzc, $haulage, 'environment', 'Fuel Storage', 'Audit Finding', 'high', 'Resources & Equipment', 'Inadequate Tools / Equipment', 9, 'awaiting', 'Diesel bowser parked outside the bunded area at the Kahama camp overnight.', 'Park the bowser inside the bund only; add spill kit at refuelling point.'],
            [14, $stg, null, 'health', 'Hygiene & Sanitation', 'Inspection Finding', 'medium', 'Health', 'Drinking Water / Sanitation Failure', 12, 'in_progress', 'Only one latrine for 60 workers at the Itigi stringing camp; handwashing station without soap.', 'Install additional latrines (1 per 25 workers) and keep handwashing stations stocked daily.'],
            [12, $kpc, null, 'social', 'Gender-Based Violence (GBV) Concern', 'Observation', 'critical', 'Social', 'GBV/SEA/SH Control Failure', 7, 'in_progress', 'Workers\' code of conduct not signed by newly mobilised subcontractor staff; no GBV awareness induction recorded.', 'Induct all new staff on the code of conduct and GBV/SEA/SH, with signed acknowledgements on file.'],
            [9, $lzc, null, 'safety', 'Housekeeping', 'Observation', 'low', 'People', 'Lack of Supervision', 14, 'in_progress', 'Rebar off-cuts and nails scattered around the T-091 work front.', 'Daily end-of-shift housekeeping check by the foreman.'],
            [7, $stg, null, 'environment', 'Vegetation Clearing', 'Regulatory Breach', 'high', 'Planning & Management', 'Inadequate Monitoring / Inspection', 21, 'open', 'Clearing extended about 15 m beyond the approved wayleave near the Ngofila forest reserve.', 'Stop clearing beyond pegged limits; re-peg the corridor; prepare a restoration plan for the over-cleared strip.'],
            [5, $kpc, $security, 'social', 'Security Incident', 'Incident', 'medium', 'Social', 'Security Management Failure', 14, 'open', 'Night guard at the Mwanza storage yard detained a trespasser for six hours before handing over to police.', 'Retrain guards on use of force and handover procedure; report to the police liaison within 1 hour.'],
            [3, $lzc, null, 'health', 'Exposure to Dust', 'Observation', 'medium', 'Resources & Equipment', 'PPE Unavailable or Inadequate', 14, 'open', 'Batching plant operators without respiratory protection during cement loading.', 'Issue FFP2 masks and fit-test operators; enclose the cement loading point.'],
            [1, $stg, null, 'safety', 'Lifting Operations', 'Near Miss', 'critical', 'People', 'Inadequate Training / Competency', 7, 'open', 'Tower section swung into the boom during a tandem lift at T-150; rigger had no valid certificate.', 'Suspend tandem lifts until a certified rigger and lift plan are in place.'],
        ]);

        foreach ($rows as $i => [$daysAgo, $contractor, $sub, $category, $subcategory, $type, $risk, $group, $cause, $targetOffset, $stage, $description, $action]) {
            $raisedOn = today()->subDays($daysAgo);

            $anomaly = new EshsAnomaly;
            $anomaly->organisation_id = $project->organisation_id;
            $anomaly->project_id = $project->id;
            $anomaly->fill([
                'raised_on' => $raisedOn,
                'location_id' => $locations === [] ? null : $locations[$i % count($locations)],
                'contractor' => $contractor,
                'subcontractor' => $sub,
                'category' => $category,
                'subcategory' => $subcategory,
                'description' => $description,
                'anomaly_type' => $type,
                'risk_rating' => $risk,
                'root_cause_group' => $group,
                'root_cause' => $cause,
                'contractor_action' => $action,
                'action_owner_name' => ['Kisare Lutambi', 'Halima Mrisho', 'Baraka Njau', 'Wei Zhang'][$i % 4],
                'action_owner_email' => ['k.lutambi', 'h.mrisho', 'b.njau', 'w.zhang'][$i % 4].'@contractor.example',
                'action_owner_phone' => $this->sample ? $this->phone() : '+255 7'.str_pad((string) (64309306 + $i * 1117), 8, '0', STR_PAD_LEFT),
                'target_date' => today()->addDays($targetOffset),
            ]);
            $anomaly->status = 'open';
            $anomaly->raised_by = $raiser->id;
            $anomaly->save();

            if ($stage === 'open') {
                continue;
            }

            $anomaly->forceFill([
                'status' => 'in_progress',
                'progress_notes' => 'Action under way; contractor HSE lead assigned.',
            ])->save();

            if (in_array($stage, ['awaiting', 'closed'], true)) {
                $this->evidence($anomaly, $raiser, 'Closure photo log', 'closure_evidence');
                $anomaly->forceFill([
                    'evidence_submitted_at' => $raisedOn->copy()->addDays(max(1, (int) ($daysAgo / 2))),
                    'closure_notes' => 'Corrective action completed. Photographs and the signed toolbox-talk register are attached.',
                ])->save();
                $this->audit->record(action: 'eshs_anomaly.evidence_submitted', entity: $anomaly, summary: 'Closure evidence submitted for verification');
            }

            if ($stage === 'closed') {
                $closedOn = today()->subDays(max(1, $daysAgo - 20 - ($i * 3)));
                $anomaly->forceFill([
                    'status' => 'closed',
                    'closed_on' => $closedOn,
                    'closed_by' => $verifier->id,
                    'verification_notes' => 'Verified on site with the contractor HSE lead; corrective action effective.',
                ])->save();
                $this->audit->record(action: 'eshs_anomaly.closed', entity: $anomaly, summary: 'Verified and closed');
            }

            // One overdue anomaly was returned once: the evidence did not show the fix.
            if ($stage === 'overdue' && $risk === 'critical') {
                $anomaly->forceFill([
                    'returned_at' => now()->subDays(4),
                    'return_reason' => 'Photographs show barricades only on two sides of the pit; shoring is not visible. Please resubmit.',
                ])->save();
                $this->audit->record(action: 'eshs_anomaly.returned', entity: $anomaly, summary: 'Returned to the contractor', context: ['reason' => $anomaly->return_reason]);
            }
        }

        return count($rows);
    }

    /** A small text file stands in for the evidence photographs in the demo. */
    private function evidence(EshsAnomaly $anomaly, ?User $uploader, string $name, string $kind): void
    {
        $disk = config('filesystems.default', 'local');
        $path = sprintf('projects/%d/eshs_anomaly/%d/%s.txt', $anomaly->project_id, $anomaly->id, Str::uuid());
        $body = "{$anomaly->reference}: {$name}\nDemo evidence placeholder.\n";

        Storage::disk($disk)->put($path, $body);

        Attachment::create([
            'organisation_id' => $anomaly->organisation_id,
            'project_id' => $anomaly->project_id,
            'attachable_type' => $anomaly->getMorphClass(),
            'attachable_id' => $anomaly->id,
            'kind' => $kind,
            'original_name' => Str::slug($name).'-'.$anomaly->reference.'.txt',
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($body),
            'checksum' => hash('sha256', $body),
            'caption' => $name,
            'scan_status' => 'clean',
            'captured_at' => now(),
            'synced_at' => now(),
            'uploaded_by' => $uploader?->id,
        ]);
    }

    // ------------------------------------------------------------- incidents

    /**
     * Incident notifications: the per-project names lists, and reports across
     * categories, consequence levels and the review lifecycle — two of them
     * serious.
     *
     * @return array<string,int>
     */
    public function incidents(Project $project, DemoCast $cast, bool $sample = false): array
    {
        $this->sample = $sample;
        $companies = $sample ? self::SAMPLE_INCIDENT_COMPANIES : self::INCIDENT_COMPANIES;
        $managers = ['Joseph Mrema', 'Rahul Deshpande'];
        $engineers = ['Eng. Neema Kweka', 'Eng. Baraka Massawe'];

        return $this->as($cast->get('pm'), function () use ($project, $cast, $companies, $managers, $engineers) {
            $this->configuration->set('incident_companies', $companies, $project->organisation_id, $project->id);
            $this->configuration->set('incident_contractor_managers', $managers, $project->organisation_id, $project->id);
            $this->configuration->set('incident_resident_engineers', $engineers, $project->organisation_id, $project->id);

            $villages = Location::where('organisation_id', $project->organisation_id)
                ->where('level', 'village')->orderBy('id')->get()->values();

            // [days ago, hour, company, site, category, type, actual, potential, status, reporter, summary, review note]
            $rows = $this->pick('incidents', [
                [2, 9, 0, 'Tower T-118 foundation pit', 'near_miss', 'near_miss', 0, 3, 'submitted', 'field',
                    'Excavator bucket swung within a metre of a banksman who had stepped inside the exclusion zone to retrieve a tape. Work stopped and the zone re-briefed.', null],
                [4, 14, 1, 'Stringing yard, Shinyanga section', 'personal_injury', 'hs_fac', 1, 2, 'submitted', 'field2',
                    'Linesman cut his left palm on a conductor strand while handling a drum. Cleaned and dressed by the site first aider; returned to work.', null],
                [6, 11, 2, 'Access road km 14, near Ibadakuli', 'materials_loss', 'ml_road_traffic', 1, 3, 'under_review', 'field',
                    'Water bowser reversed into a parked pickup at the batching area. Rear light cluster and tailgate damaged. No injuries.',
                    'Reversing without a banksman. Contractor to retrain drivers and fit reversing alarms on all bowsers.'],
                [8, 7, 0, 'Camp fuel station, Singida', 'environmental', 'env_spill_hydrocarbon', 2, 3, 'under_review', 'hse',
                    'Roughly 120 litres of diesel spilled when a transfer hose split during refuelling. Contained by the bund; contaminated soil excavated and drummed.',
                    'Hose inspection log not kept. Spill kit restocked; contractor to replace all transfer hoses older than 12 months.'],
                [11, 16, 1, 'Tower T-097, Mwanza section', 'personal_injury', 'hs_lti', 3, 4, 'under_review', 'hse',
                    'Rigger fell approximately 2.5 m from the tower climbing ladder while repositioning his lanyard and fractured his right wrist. Taken to Bugando hospital; off work for at least three weeks.',
                    'Serious potential. Stand-down held across all tower crews; 100% tie-off audit under way.'],
                [13, 10, 2, 'Village access track near the primary school', 'social', 'soc_asset_damage', 1, 2, 'closed', 'field2',
                    'Low-bed trailer clipped and broke a section of a household\'s fence and two banana plants while turning at the junction.',
                    'Household compensated in agreement with the village chairperson; turning point widened.'],
                [16, 22, 0, 'Laydown yard, Ilemela', 'security', 'sec_theft', 2, 2, 'closed', 'security',
                    'Twelve galvanised angle members (approximately 400 kg) stolen from the laydown yard overnight. Fence cut on the eastern side.',
                    'Reported to Ilemela police. Fence repaired, additional night guard deployed, lighting added.'],
                [19, 13, 1, 'Tower T-131 erection site', 'materials_loss', 'ml_dropped_objects', 0, 3, 'closed', 'field',
                    'A 3 kg bolt bag fell about 20 m from the tower top, landing inside the drop zone but within two metres of a ground worker. No injury.',
                    'Tool and bag tethering made mandatory; drop zone extended to 1.5× height.'],
                [22, 8, 2, 'Riverside crossing, Manonga river', 'environmental', 'env_waste_management', 1, 2, 'closed', 'hse',
                    'Cement bags and food packaging found burned in an open pit beside the river crossing work front.',
                    'Waste collection points set up at each work front; toolbox talk on the waste management plan.'],
                [26, 15, 0, 'Camp, Singida', 'personal_injury', 'hs_oi', 1, 2, 'submitted', 'hse',
                    'Two camp kitchen staff reported respiratory irritation after charcoal stove use in a poorly ventilated store.', null],
                [33, 18, 1, 'Community borehole, Nyakato', 'social', 'soc_local_workforce_health', 1, 3, 'under_review', 'field2',
                    'Villagers report workers washing vehicles at the community borehole, leaving oily runoff around the drinking-water point.',
                    'Contractor instructed to stop immediately; water quality sample taken.'],
                [41, 6, 2, 'Tower T-060, Shinyanga section', 'near_miss', 'ml_lifting', 0, 3, 'closed', 'field',
                    'Crane outrigger pad sank into soft ground during a lift; the lift was lowered safely before the crane became unstable.',
                    'Ground bearing check now required before every lift; timber mats issued.'],
                [52, 12, 0, 'Access road km 3', 'materials_loss', 'ml_serious_road_traffic', 3, 5, 'closed', 'security',
                    'Contractor pickup overturned after swerving to avoid a motorcycle. Driver and passenger hospitalised overnight; vehicle written off.',
                    'Speed limiters fitted to all light vehicles; journey management plan reissued.'],
                [64, 9, 1, 'Substation extension, Mwanza', 'personal_injury', 'hs_mtc', 2, 3, 'closed', 'hse',
                    'Electrician received a foreign body in the eye while grinding without a face shield; removed at the clinic.',
                    'Face shields issued with every grinder; PPE spot checks increased.'],
            ]);

            $manager = $cast->get('hse');

            foreach ($rows as $index => [$daysAgo, $hour, $company, $site, $category, $type, $actual, $potential, $status, $reporter, $summary, $note]) {
                $occurredAt = now()->subDays($daysAgo)->setTime($hour, ($index * 7) % 60);
                $reporterUser = $cast->get($reporter);

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

            return ['incidents' => count($rows)];
        });
    }

    // --------------------------------------------------------------- helpers

    /**
     * Run as one person (the author the services and audit trail record),
     * then put back whoever was signed in before.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    private function as(User $user, callable $work): mixed
    {
        $previous = Auth::user();
        Auth::setUser($user);

        try {
            return $work();
        } finally {
            if ($previous) {
                Auth::setUser($previous);
            } else {
                Auth::forgetUser();
            }
        }
    }

    /**
     * The showcase list as it is, or (for a demo workspace) the sample subset
     * with real organisations' names replaced. Row keys are kept, because the
     * stories rotate villages and people by row.
     *
     * @param  array<int,array<int,mixed>>  $rows
     * @return array<int,array<int,mixed>>
     */
    private function pick(string $set, array $rows): array
    {
        if (! $this->sample) {
            return $rows;
        }

        $picked = array_intersect_key($rows, array_flip(self::SAMPLE[$set]));

        return array_map(fn (array $row) => array_map(
            fn ($value) => is_string($value) ? $this->fictional($value) : (is_array($value) ? $this->fictionalArray($value) : $value),
            $row
        ), $picked);
    }

    /** @param array<mixed> $values @return array<mixed> */
    private function fictionalArray(array $values): array
    {
        return array_map(fn ($value) => is_string($value) ? $this->fictional($value) : (is_array($value) ? $this->fictionalArray($value) : $value), $values);
    }

    private function fictional(string $text): string
    {
        return $this->sample ? strtr($text, self::FICTIONAL_NAMES) : $text;
    }

    /**
     * Showcase: a random Tanzanian mobile number, as before. Demo workspace:
     * +255 700 000 xxx, one obviously made-up block, so sample people never
     * carry a number that looks like (or is) a real person's.
     */
    private function phone(): string
    {
        if (! $this->sample) {
            return '+255 7'.rand(10, 89).' '.rand(100, 999).' '.rand(100, 999);
        }

        return sprintf('+255 700 000 %03d', ++$this->phoneSequence % 1000);
    }
}
