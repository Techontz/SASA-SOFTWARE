<?php

namespace App\Domain\Tenancy;

use App\Domain\Notification\NotificationEvents;
use App\Domain\Reporting\ReportService;
use App\Models\GrievanceCategory;
use App\Models\Holiday;
use App\Models\NotificationRule;
use App\Models\Project;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\WorkingCalendar;

/**
 * The default configuration every new project starts with: a working
 * calendar with the national public holidays, the grievance category set,
 * the SLA policies from config/sasa.php, the notification rules and the
 * system report definitions. Idempotent: running it twice changes nothing.
 *
 * Each of these is then the project's own configuration to change.
 */
final class ProjectFoundations
{
    /** The category set, taken verbatim from the source document. [key, name, default severity, restricted, subcategories] */
    public const CATEGORIES = [
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

    /** Tanzanian public holidays. "7 working days" means nothing without them. */
    public const HOLIDAYS = [
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

    public function apply(Project $project): WorkingCalendar
    {
        $calendar = $this->calendar($project);
        $this->categories($project);
        $this->slaPolicies($project, $calendar);
        $this->notificationRules($project);
        $this->reportDefinitions($project);

        return $calendar;
    }

    public function calendar(Project $project): WorkingCalendar
    {
        $calendar = WorkingCalendar::updateOrCreate(
            ['organisation_id' => $project->organisation_id, 'project_id' => $project->id],
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

        foreach (self::HOLIDAYS as [$monthDay, $name]) {
            Holiday::updateOrCreate(
                ['working_calendar_id' => $calendar->id, 'date' => now()->year.'-'.$monthDay],
                ['name' => $name, 'recurs_annually' => true]
            );
        }

        return $calendar;
    }

    public function categories(Project $project): void
    {
        foreach (self::CATEGORIES as $index => [$key, $name, $defaultSeverity, $restricted, $subcategories]) {
            $category = GrievanceCategory::updateOrCreate(
                ['organisation_id' => $project->organisation_id, 'project_id' => $project->id, 'key' => $key],
                [
                    'name' => $name,
                    'sort_order' => $index,
                    'default_severity' => $defaultSeverity,
                    'is_restricted' => $restricted,
                    'handling_groups' => $restricted ? ['restricted_handling'] : null,
                    'aggregate_reporting_only' => $restricted,
                ]
            );

            foreach (array_keys($subcategories) as $subIndex => $subKey) {
                GrievanceCategory::updateOrCreate(
                    ['organisation_id' => $project->organisation_id, 'project_id' => $project->id, 'key' => $key.'.'.$subKey],
                    [
                        'parent_id' => $category->id,
                        'name' => $subcategories[$subKey],
                        'sort_order' => $subIndex,
                        'default_severity' => $defaultSeverity,
                    ]
                );
            }
        }
    }

    public function slaPolicies(Project $project, WorkingCalendar $calendar): void
    {
        $escalateTo = Role::whereNull('organisation_id')->where('key', 'project_admin')->first();

        foreach (config('sasa.sla.defaults') as $clock => $default) {
            SlaPolicy::updateOrCreate(
                [
                    'organisation_id' => $project->organisation_id,
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
                        'organisation_id' => $project->organisation_id,
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

    public function notificationRules(Project $project): void
    {
        foreach (NotificationEvents::defaults() as $default) {
            NotificationRule::updateOrCreate(
                [
                    'organisation_id' => $project->organisation_id,
                    'project_id' => $project->id,
                    'event_key' => $default['event_key'],
                ],
                array_merge($default, ['is_active' => true])
            );
        }
    }

    public function reportDefinitions(Project $project): void
    {
        foreach (ReportService::systemDefinitions() as $definition) {
            ReportDefinition::updateOrCreate(
                [
                    'organisation_id' => $project->organisation_id,
                    'project_id' => $project->id,
                    'key' => $definition['key'],
                ],
                array_merge($definition, ['is_system' => true, 'is_active' => true])
            );
        }
    }
}
