<?php

namespace App\Domain\Configuration;

/**
 * The configurable surface, in one place. Every key here is editable per
 * project through /api/v1/configuration and every change is audited.
 */
final class ConfigurationKeys
{
    public const STAKEHOLDER_TYPES = 'stakeholder_types';

    public const VULNERABILITY_CATEGORIES = 'vulnerability_categories';

    public const ENGAGEMENT_METHODS = 'engagement_methods';

    public const PROJECT_PHASES = 'project_phases';

    public const LANGUAGES = 'languages';

    public const PRIORITY = 'priority';

    public const SEVERITY_LEVELS = 'severity_levels';

    public const DISAGGREGATION = 'disaggregation';

    public const DISAGGREGATION_DIMENSIONS = 'disaggregation_dimensions';

    public const CUSTOM_FIELDS = 'custom_fields';

    public const COMMITMENT_REMINDERS = 'commitments';

    public const VOICE = 'voice';

    public const SLA_REMINDERS = 'sla_reminders';

    public const REPORTING = 'reports';

    /** @return array<string,array{label:string,group:string,description:string}> */
    public static function catalogue(): array
    {
        return [
            self::STAKEHOLDER_TYPES => ['label' => 'Stakeholder types', 'group' => 'Register', 'description' => 'The list of stakeholder types available when adding a record.'],
            self::VULNERABILITY_CATEGORIES => ['label' => 'Vulnerability categories', 'group' => 'Register', 'description' => 'Categories used to flag vulnerable stakeholders.'],
            self::PRIORITY => ['label' => 'Priority weighting', 'group' => 'Register', 'description' => 'Weights and thresholds for the stakeholder priority score. A weight of 0 disables a dimension.'],
            self::ENGAGEMENT_METHODS => ['label' => 'Engagement methods', 'group' => 'Engagement', 'description' => 'How engagements are carried out — meeting, radio, door-to-door and so on.'],
            self::PROJECT_PHASES => ['label' => 'Project phases', 'group' => 'Engagement', 'description' => 'The phases an engagement or plan can belong to.'],
            self::COMMITMENT_REMINDERS => ['label' => 'Commitment reminders', 'group' => 'Engagement', 'description' => 'When commitment owners are reminded, relative to the due date.'],
            self::SEVERITY_LEVELS => ['label' => 'Severity levels', 'group' => 'Grievances', 'description' => 'The 1–5 severity scale and what each level means on this project.'],
            self::LANGUAGES => ['label' => 'Languages', 'group' => 'Grievances', 'description' => 'Languages a concern can be recorded in.'],
            self::DISAGGREGATION => ['label' => 'Disaggregation safety', 'group' => 'Reporting', 'description' => 'Minimum cell size before a cross-tab is displayed, to prevent re-identification.'],
            self::DISAGGREGATION_DIMENSIONS => ['label' => 'Disaggregation dimensions', 'group' => 'Reporting', 'description' => 'Which optional demographic dimensions this project collects. Sensitive dimensions are never mandatory.'],
            self::CUSTOM_FIELDS => ['label' => 'Custom fields', 'group' => 'Register', 'description' => 'Extra project-specific fields on stakeholders, engagements, grievances and commitments.'],
            self::VOICE => ['label' => 'AI voice agent', 'group' => 'AI', 'description' => 'Call length cap, behaviour at the cap, consent wording and languages.'],
            self::REPORTING => ['label' => 'Reporting calendar', 'group' => 'Reporting', 'description' => 'Fiscal year start and default reporting periods.'],
        ];
    }
}
