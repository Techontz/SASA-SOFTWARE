<?php

namespace Database\Seeders;

use App\Domain\Engagement\CommitmentService;
use App\Domain\Engagement\ConcernService;
use App\Domain\Engagement\EngagementService;
use App\Domain\Grievance\GrievanceService;
use App\Domain\Grievance\IntakeEngine;
use App\Domain\Stakeholder\StakeholderService;
use App\Models\Commitment;
use App\Models\Concern;
use App\Models\Engagement;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\Location;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * The operational history: a register, an engagement programme, concerns that
 * became commitments, and a case load in every state of the lifecycle —
 * including breaches, escalations and a reopened case, because a demo where
 * everything is green teaches nothing.
 */
class DemoOperationsSeeder extends Seeder
{
    private Project $project;

    /** @var array<string,User> */
    private array $users = [];

    /** @var array<int,Location> */
    private array $villages = [];

    /** @var array<string,GrievanceCategory> */
    private array $categories = [];

    public function run(
        StakeholderService $stakeholders,
        EngagementService $engagements,
        ConcernService $concerns,
        CommitmentService $commitments,
        IntakeEngine $intake,
        GrievanceService $grievances,
    ): void {
        $this->project = Project::where('code', 'NBT-400KV')->firstOrFail();

        if (Stakeholder::where('project_id', $this->project->id)->exists()) {
            $this->command?->warn('Operational demo data already present — skipping.');

            return;
        }

        $this->users = User::whereIn('email', [
            'admin@sasa.test', 'project.admin@sasa.test', 'executive@sasa.test', 'pm@sasa.test',
            'grievance@sasa.test', 'cro@sasa.test', 'hr@sasa.test', 'hse@sasa.test',
            'security@sasa.test', 'field@sasa.test', 'field2@sasa.test',
        ])->get()->keyBy('email')->all();

        $this->villages = Location::where('organisation_id', $this->project->organisation_id)
            ->where('level', 'village')
            ->whereHas('parent.parent.parent', fn ($q) => $q->whereIn('name', ['Singida', 'Shinyanga', 'Mwanza']))
            ->get()->all();

        if ($this->villages === []) {
            $this->villages = Location::where('level', 'village')->get()->all();
        }

        $this->categories = GrievanceCategory::where('project_id', $this->project->id)
            ->get()->keyBy('key')->all();

        Auth::login($this->users['cro@sasa.test']);

        $register = $this->seedStakeholders($stakeholders);
        $this->seedEngagements($engagements, $register);
        $this->seedGrievances($intake, $grievances, $register);
        $this->seedStandaloneCommitments($commitments, $register);

        Auth::logout();

        $this->command?->info(sprintf(
            'Seeded %d stakeholders, %d engagements, %d concerns, %d commitments and %d grievances on %s.',
            Stakeholder::where('project_id', $this->project->id)->count(),
            Engagement::where('project_id', $this->project->id)->count(),
            Concern::where('project_id', $this->project->id)->count(),
            Commitment::where('project_id', $this->project->id)->count(),
            Grievance::where('project_id', $this->project->id)->count(),
            $this->project->name,
        ));
    }

    /** @return array<int,Stakeholder> */
    private function seedStakeholders(StakeholderService $service): array
    {
        $definitions = [
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
        ];

        $owners = [$this->users['cro@sasa.test']->id, $this->users['field@sasa.test']->id, $this->users['field2@sasa.test']->id];
        $languages = ['sw', 'sw', 'sw', 'en'];
        $created = [];

        foreach ($definitions as $index => [$name, $type, $influence, $interest, $power, $impact, $vulnerable, $notes]) {
            $village = $this->villages[$index % count($this->villages)];

            $created[] = $service->create($this->project, [
                'name' => $name,
                'type' => $type,
                'phone' => '+255 7'.rand(10, 89).' '.rand(100, 999).' '.rand(100, 999),
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

    private function seedEngagements(EngagementService $service, array $register): void
    {
        $programme = [
            ['Corridor disclosure meeting — Buswelu', 'community_meeting', -95, 'on_plan', 180, [
                ['Dust from the access road is reaching the school', 'high', 'environmental_health_safety'],
                ['Compensation for three households is still not paid', 'high', 'land_assets_livelihoods'],
            ], [
                ['Water the access road twice daily during dry-season works', 14, 'high'],
                ['Publish the compensation payment schedule at the village office within two weeks', 14, 'medium'],
            ]],
            ['Focus group discussion with women — Ilemela', 'focus_group', -82, 'on_plan', 42, [
                ['Women are not being told about job opportunities in time', 'medium', 'local_employment_economic'],
            ], [
                ['Post all vacancies at the village office 7 days before closing', 30, 'medium'],
            ]],
            ['Household survey — Isaka corridor', 'household_visit', -74, 'late', 65, [
                ['Crop damage during survey pegging was not recorded', 'medium', 'land_assets_livelihoods'],
            ], [
                ['Re-survey the affected plots with the farmers present', 21, 'high'],
            ]],
            ['Cultural heritage consultation — Nduguti ritual site', 'community_meeting', -60, 'on_plan', 55, [
                ['Tower 142 is 40 m from the ritual site boundary', 'high', 'cultural_heritage'],
            ], [
                ['Commission a chance-find procedure and brief all contractors before clearing', 45, 'high'],
            ]],
            ['Quarterly disclosure — Kahama District Council', 'public_hearing', -48, 'on_plan', 96, [], [
                ['Share the quarterly environmental monitoring report with the district', 30, 'low'],
            ]],
            ['Grievance mechanism awareness — Shelui', 'community_meeting', -35, 'unplanned', 120, [
                ['People do not know the toll-free number exists', 'medium', 'community_relations_engagement'],
            ], [
                ['Print and post the grievance channel poster in all 12 villages', 21, 'medium'],
            ]],
            ['Contractor toolbox talk — Baraka Construction', 'workshop', -28, 'on_plan', 74, [
                ['Workers report PPE is not replaced when damaged', 'high', 'labor_hr_industrial_relations'],
            ], [
                ['Contractor to restock PPE and report weekly for one month', 7, 'high'],
            ]],
            ['Grazing route negotiation — Nyandekwa', 'community_meeting', -21, 'on_plan', 88, [
                ['Cattle route is blocked by the construction laydown area', 'high', 'land_assets_livelihoods'],
            ], [
                ['Open a signed cattle crossing at chainage 214 within one month', 30, 'high'],
            ]],
            ['Door-to-door engagement — vulnerable households', 'household_visit', -14, 'on_plan', 18, [], [
                ['Arrange transport for three residents to the district compensation office', 10, 'medium'],
            ]],
            ['Village assembly — Bulige', 'community_meeting', -6, 'on_plan', 210, [
                ['The borehole promised last year has not been drilled', 'high', 'community_relations_engagement'],
            ], [
                ['Confirm the borehole budget and give the village a written date', 30, 'high'],
            ]],
        ];

        foreach ($programme as $index => [$topic, $method, $daysAgo, $variance, $attendance, $concerns, $commitments]) {
            $village = $this->villages[$index % count($this->villages)];
            $heldAt = now()->subDays(abs($daysAgo));

            $plan = null;

            if ($variance !== 'unplanned') {
                $targetDate = $variance === 'late' ? $heldAt->copy()->subDays(9) : $heldAt->copy();

                $plan = $service->createPlan($this->project, [
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
                    'owner_id' => $this->users['cro@sasa.test']->id,
                    'responsible_team' => 'Community relations',
                    'priority' => $index % 3 === 0 ? 'high' : 'medium',
                    'status' => 'planned',
                ]);
            }

            $female = (int) round($attendance * (rand(35, 55) / 100));

            $service->logEngagement($this->project, [
                'engagement_plan_id' => $plan?->id,
                'topic' => $topic,
                'project_phase' => 'construction',
                'location_id' => $village->id,
                'location_text' => $village->path,
                'held_at' => $heldAt->setTime(10, 0),
                'ended_at' => $heldAt->setTime(13, 30),
                'method' => $method,
                'venue' => $village->name.' village office',
                'organised_by' => 'Nyanda Infrastructure Group — Community Relations',
                'facilitator_id' => $this->users['cro@sasa.test']->id,
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
                    'grievance_category_id' => $this->categories[$concern[2]]->id ?? null,
                    'raised_by' => 'Meeting attendee',
                    'raised_on' => $heldAt->toDateString(),
                ])->all(),
                'commitments' => collect($commitments)->map(fn ($commitment, $i) => [
                    'commitment_text' => $commitment[0],
                    'owner_id' => [$this->users['cro@sasa.test']->id, $this->users['hse@sasa.test']->id, $this->users['pm@sasa.test']->id][$i % 3],
                    'due_date' => $heldAt->copy()->addDays($commitment[1])->toDateString(),
                    'risk_level' => $commitment[2],
                    'stakeholder_ids' => [$register[$index % count($register)]->id],
                ])->all(),
            ]);
        }

        // A plan whose window has passed with nothing logged — a real "missed".
        $service->createPlan($this->project, [
            'title' => 'Resettlement committee meeting — Kiomboi',
            'project_phase' => 'construction',
            'purpose' => 'Agree the replacement land allocation for four households.',
            'method' => 'community_meeting',
            'target_date' => now()->subDays(11)->toDateString(),
            'location_id' => $this->villages[0]->id,
            'owner_id' => $this->users['cro@sasa.test']->id,
            'priority' => 'high',
            'status' => 'planned',
        ]);

        // And several genuinely upcoming ones, so the calendar is not empty.
        foreach ([4, 9, 16, 23, 38] as $offset) {
            $village = $this->villages[$offset % count($this->villages)];

            $service->createPlan($this->project, [
                'title' => 'Quarterly disclosure meeting — '.$village->name,
                'project_phase' => 'construction',
                'purpose' => 'Disclose the works programme for the coming quarter and review commitments.',
                'method' => 'community_meeting',
                'target_date' => now()->addDays($offset)->toDateString(),
                'location_id' => $village->id,
                'location_text' => $village->path,
                'vulnerable_group_accommodation' => true,
                'accommodation_notes' => 'Swahili interpretation; meeting held at ground level for wheelchair access.',
                'grievance_channel_available' => true,
                'owner_id' => $this->users['cro@sasa.test']->id,
                'responsible_team' => 'Community relations',
                'priority' => $offset < 10 ? 'high' : 'medium',
                'status' => 'planned',
            ]);
        }
    }

    private function seedGrievances(IntakeEngine $intake, GrievanceService $service, array $register): void
    {
        $cases = [
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
                'Restricted case — handled by the named handling group',
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
                'Restricted case — handled by the named handling group',
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
        ];

        foreach ($cases as $index => [$daysAgo, $channel, $categoryKey, $subKey, $severity, $confidentiality, $title, $description, $desired, $lifecycle]) {
            $receivedAt = now()->subDays(abs($daysAgo));
            $village = $this->villages[$index % count($this->villages)];
            $category = $this->categories[$categoryKey] ?? null;
            $subcategory = $this->categories[$categoryKey.'.'.$subKey] ?? null;
            $stakeholder = $register[$index % count($register)];

            Auth::login($this->users['grievance@sasa.test']);

            $identity = $confidentiality === 'anonymous' ? [] : [
                'complainant_name' => $stakeholder->name,
                'complainant_phone' => $stakeholder->phone,
                'complainant_type' => 'community_member',
                'stakeholder_id' => $stakeholder->id,
            ];

            $grievance = $intake->intake($this->project, array_merge($identity, [
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

            $this->advanceLifecycle($service, $grievance, $lifecycle, $receivedAt, $index);
        }

        // A reopened case: the complainant did not accept the resolution.
        $reopened = Grievance::where('project_id', $this->project->id)
            ->where('status', 'closed')->orderBy('id')->first();

        if ($reopened) {
            Auth::login($this->users['grievance@sasa.test']);
            $service->reopen($reopened, 'The complainant says the road was watered for three days and then stopped. The dust is back.');
            $service->assign($reopened, $this->users['hse@sasa.test']->id, 'HSE', 'Reassigned to HSE for a durable fix rather than a temporary one.');
        }
    }

    private function advanceLifecycle(GrievanceService $service, Grievance $grievance, string $target, $receivedAt, int $index): void
    {
        $order = ['new', 'classified', 'assigned', 'acknowledged', 'under_investigation', 'action_pending', 'resolved', 'closed'];
        $targetIndex = array_search($target, $order, true);

        if ($targetIndex === false || $targetIndex === 0) {
            return;
        }

        $assignees = [
            'environmental_health_safety' => 'hse@sasa.test',
            'labor_hr_industrial_relations' => 'hr@sasa.test',
            'human_rights_workplace_conduct' => 'hr@sasa.test',
            'ethics_compliance' => 'grievance@sasa.test',
        ];

        $categoryKey = $grievance->category?->key;
        $assignee = $this->users[$assignees[$categoryKey] ?? 'grievance@sasa.test'];

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

        Auth::login($assignee);

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

    private function seedStandaloneCommitments(CommitmentService $service, array $register): void
    {
        Auth::login($this->users['pm@sasa.test']);

        $definitions = [
            ['Build two additional classrooms at Msingi Primary School as part of the community benefit package', 120, 'high', 'open'],
            ['Employ at least 60% of unskilled labour from the four host districts across the construction period', -25, 'high', 'overdue'],
            ['Rehabilitate the Kiomboi–Shelui access road on completion of tower works', 90, 'medium', 'open'],
            ['Deliver a quarterly environmental monitoring report to each district council', -6, 'medium', 'overdue'],
            ['Provide replacement grazing access at three points along the corridor', 45, 'high', 'in_progress'],
            ['Run a skills training programme for 40 young people from the host wards', 200, 'medium', 'open'],
            ['Fence and mark the substation perimeter before energisation', -40, 'high', 'fulfilled'],
            ['Restore all borrow pits to agreed condition before demobilisation', 365, 'medium', 'open'],
        ];

        foreach ($definitions as $index => [$text, $dueOffset, $risk, $status]) {
            $commitment = $service->create($this->project, [
                'commitment_text' => $text,
                'source_type' => 'manual',
                'source_date' => now()->subDays(rand(40, 200))->toDateString(),
                'owner_id' => [$this->users['pm@sasa.test']->id, $this->users['cro@sasa.test']->id, $this->users['hse@sasa.test']->id][$index % 3],
                'owner_team' => ['Project management', 'Community relations', 'HSE'][$index % 3],
                'due_date' => now()->addDays($dueOffset)->toDateString(),
                'risk_level' => $risk,
                'priority' => $risk,
                'location_id' => $this->villages[$index % count($this->villages)]->id,
                'stakeholder_ids' => [$register[$index % count($register)]->id],
                'status' => in_array($status, ['open', 'in_progress'], true) ? $status : 'open',
            ]);

            if ($status === 'overdue') {
                $service->changeStatus($commitment, 'overdue');
            }

            if ($status === 'fulfilled') {
                $service->changeStatus($commitment, 'fulfilled', [
                    'completed_on' => now()->subDays(abs($dueOffset) - 5)->toDateString(),
                    'evidence_notes' => 'Completion photographs and the contractor\'s sign-off sheet are attached.',
                ]);

                // One fulfilled but unverified, so the register shows the gap.
                if ($index % 2 === 0) {
                    Auth::login($this->users['cro@sasa.test']);
                    $service->verify($commitment->fresh(), 'verified', 'Verified on site with the village chairperson present.');
                    Auth::login($this->users['pm@sasa.test']);
                }
            }
        }
    }
}
