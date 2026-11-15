<?php

namespace App\Domain\Configuration;

use App\Models\Project;
use App\Models\Stakeholder;

/**
 * The option lists the forms are built from — engagement methods, phases,
 * languages and the rest. They are not sensitive, so everyone on a project
 * receives them with the device bootstrap; only changing them needs
 * configuration rights.
 */
final class ReferenceLists
{
    public function __construct(private readonly ConfigurationRegistry $configuration) {}

    public function for(Project $project): array
    {
        $get = fn (string $key, array $default) => $this->configuration->get($key, $project->organisation_id, $project->id, $default);

        return [
            'stakeholder_types' => $get('stakeholder_types', Stakeholder::TYPES),
            'engagement_methods' => $get('engagement_methods', [
                'community_meeting', 'focus_group', 'household_visit', 'public_hearing',
                'radio_programme', 'phone_call', 'written_disclosure', 'workshop', 'site_walkover',
            ]),
            'project_phases' => $get('project_phases', [
                'pre_construction', 'construction', 'operation', 'decommissioning',
            ]),
            'languages' => $get('languages', ['sw', 'en']),
            'vulnerability_categories' => $get('vulnerability_categories', [
                'elderly', 'disability', 'female_headed_household', 'child_headed_household',
                'landless', 'chronically_ill', 'displaced', 'indigenous', 'linguistic_minority', 'low_income',
            ]),
            'disaggregation_dimensions' => $get('disaggregation_dimensions', self::defaultDimensions()),
        ];
    }

    public static function defaultDimensions(): array
    {
        return [
            ['key' => 'gender', 'label' => 'Gender', 'sensitive' => false, 'enabled' => true, 'options' => ['female', 'male', 'other', 'prefer_not_to_say']],
            ['key' => 'age_band', 'label' => 'Age band', 'sensitive' => false, 'enabled' => true, 'options' => ['under_18', '18_35', '36_60', 'over_60']],
            ['key' => 'disability', 'label' => 'Disability status', 'sensitive' => true, 'enabled' => true, 'options' => ['yes', 'no', 'prefer_not_to_say']],
            ['key' => 'employment', 'label' => 'Employment status', 'sensitive' => false, 'enabled' => true, 'options' => ['employed_by_project', 'contractor', 'self_employed', 'farmer', 'unemployed', 'student', 'retired']],
            ['key' => 'language', 'label' => 'Language', 'sensitive' => false, 'enabled' => true, 'options' => []],
            ['key' => 'marital_status', 'label' => 'Marital status', 'sensitive' => true, 'enabled' => false, 'options' => ['single', 'married', 'widowed', 'separated', 'orphan']],
            ['key' => 'migrant_status', 'label' => 'Migrant or refugee status', 'sensitive' => true, 'enabled' => false, 'options' => ['resident', 'internal_migrant', 'refugee', 'prefer_not_to_say']],
            ['key' => 'economic_status', 'label' => 'Economic status', 'sensitive' => true, 'enabled' => false, 'options' => ['low', 'medium', 'high', 'prefer_not_to_say']],
            // Deliberately off by default: collecting this lawfully, voluntarily
            // and safely is a decision for the project, not a default.
            ['key' => 'gender_identity', 'label' => 'Gender identity or sexual orientation', 'sensitive' => true, 'enabled' => false, 'options' => []],
        ];
    }
}
