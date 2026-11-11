<?php

/*
|--------------------------------------------------------------------------
| SASA platform defaults
|--------------------------------------------------------------------------
|
| These are ORGANISATION-LEVEL DEFAULTS ONLY. Every value here can be
| overridden per organisation and again per project through the
| `configurations` table (see App\Domain\Configuration\ConfigurationRegistry).
|
| The rule from the blueprint: "anything that differs between projects is
| configuration; anything that is the same everywhere is code."
|
*/

return [

    'web_url' => env('SASA_WEB_URL', 'http://localhost:3000'),

    'auth' => [
        'token_ttl_minutes' => (int) env('SASA_TOKEN_TTL_MINUTES', 720),
        'admin_token_ttl_minutes' => (int) env('SASA_ADMIN_TOKEN_TTL_MINUTES', 240),
        'require_mfa_for_admins' => (bool) env('SASA_REQUIRE_MFA_FOR_ADMINS', false),
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
        'password_min_length' => 10,
    ],

    'files' => [
        'max_mb' => (int) env('SASA_ATTACHMENT_MAX_MB', 25),
        'signed_url_ttl_minutes' => (int) env('SASA_SIGNED_URL_TTL_MINUTES', 10),
        'virus_scan_enabled' => (bool) env('SASA_VIRUS_SCAN_ENABLED', false),
        'virus_scan_command' => env('SASA_VIRUS_SCAN_COMMAND', 'clamdscan --no-summary --stdout'),
        'allowed_mimes' => [
            'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv', 'text/plain',
            'audio/mpeg', 'audio/wav', 'audio/webm', 'audio/ogg', 'audio/mp4',
            'video/mp4', 'video/webm', 'video/quicktime',
        ],
    ],

    /*
    | Reference identifier formats. Sequences are allocated per project with a
    | row lock so two field officers can never receive the same number.
    */
    'reference_ids' => [
        'stakeholder' => ['prefix' => 'STK', 'pad' => 4],
        'engagement_plan' => ['prefix' => 'PLAN', 'pad' => 4],
        'engagement' => ['prefix' => 'ENG', 'pad' => 4],
        'concern' => ['prefix' => 'CON', 'pad' => 4],
        'commitment' => ['prefix' => 'COM', 'pad' => 4],
        'grievance' => ['prefix' => 'GRV', 'pad' => 4],
        'report' => ['prefix' => 'REP', 'pad' => 4],
        // PAP Master Register
        'pap' => ['prefix' => 'PAP', 'pad' => 4],
        'pap_household' => ['prefix' => 'HH', 'pad' => 4],
        'pap_land' => ['prefix' => 'LND', 'pad' => 4],
        'pap_asset' => ['prefix' => 'AST', 'pad' => 4],
        'pap_crop' => ['prefix' => 'CRP', 'pad' => 4],
        'pap_valuation' => ['prefix' => 'VAL', 'pad' => 4],
        'pap_entitlement' => ['prefix' => 'ENT', 'pad' => 4],
        'pap_payment' => ['prefix' => 'PAY', 'pad' => 4],
        'pap_relocation' => ['prefix' => 'REL', 'pad' => 4],
        'pap_livelihood' => ['prefix' => 'LRP', 'pad' => 4],
        'pap_consultation' => ['prefix' => 'CNS', 'pad' => 4],
        'pap_document' => ['prefix' => 'DOC', 'pad' => 4],
        // ESHS contractor assurance
        'eshs_anomaly' => ['prefix' => 'ESA', 'pad' => 4],
        // Incident notification
        'incident' => ['prefix' => 'INC', 'pad' => 4],
    ],

    /*
    | Stakeholder priority engine (blueprint §7.3).
    | score = w1*influence + w2*interest + w3*power + w4*impact
    | High = 3, Medium = 2, Low = 1. A weight of 0 disables the dimension.
    |
    | AMBIGUITY (blueprint §7.2): the source five-strategy matrix is internally
    | inconsistent. We do not silently correct it — the calculation below is a
    | configurable default and the strategy label hangs off the priority band.
    */
    'priority' => [
        'weights' => [
            'influence' => 1,
            'interest' => 1,
            'power' => 1,
            'impact' => 1,
        ],
        'levels' => ['high' => 3, 'medium' => 2, 'low' => 1],
        'thresholds' => [
            'high' => 10,   // score >= 10
            'medium' => 7,  // score 7..9
        ],
        'bands' => [
            'high' => [
                'strategy' => 'Engage closely and involve in decisions',
                'frequency' => 'Monthly',
            ],
            'medium' => [
                'strategy' => 'Keep informed and consult on relevant topics',
                'frequency' => 'Quarterly',
            ],
            'low' => [
                'strategy' => 'Monitor and track',
                'frequency' => 'Semi-annually',
            ],
        ],
    ],

    /*
    | Working calendar defaults. "7 working days" means nothing without these.
    */
    'calendar' => [
        'timezone' => 'Africa/Dar_es_Salaam',
        'working_days' => [1, 2, 3, 4, 5], // Mon..Fri (ISO-8601)
        'working_hours' => ['start' => '08:00', 'end' => '17:00'],
    ],

    /*
    | SLA engine defaults. Nothing is hard-coded to "7 working days" — these
    | seed the project's sla_policies rows and can be edited per project,
    | country, category, severity and clock type.
    */
    'sla' => [
        'reminder_thresholds' => [50, 80], // percent elapsed
        'defaults' => [
            // clock => [unit, value]
            'acknowledgement' => ['unit' => 'working_days', 'value' => 2],
            'investigation' => ['unit' => 'working_days', 'value' => 10],
            'resolution' => ['unit' => 'working_days', 'value' => 7],
        ],
        'severity_overrides' => [
            5 => ['acknowledgement' => 1, 'investigation' => 3, 'resolution' => 5],
            4 => ['acknowledgement' => 1, 'investigation' => 5, 'resolution' => 7],
        ],
    ],

    'commitments' => [
        // Days relative to due date at which reminders fire. Negative = before.
        'reminder_offsets' => [-14, -3, 0, 7, 30],
        'high_risk_escalates_immediately' => true,
    ],

    'grievances' => [
        'severity_levels' => [
            1 => ['label' => 'Level 1 — Minor', 'description' => 'Localised, easily resolved, no lasting effect.'],
            2 => ['label' => 'Level 2 — Moderate', 'description' => 'Affects a household or small group; resolvable at project level.'],
            3 => ['label' => 'Level 3 — Significant', 'description' => 'Affects a community or recurs; requires management attention.'],
            4 => ['label' => 'Level 4 — Major', 'description' => 'Serious harm, legal exposure or widespread impact. Management alert.'],
            5 => ['label' => 'Level 5 — Critical', 'description' => 'Life-threatening, rights violation or reputational crisis. Executive alert.'],
        ],
        'management_alert_from_severity' => 4,
        'anonymous_allowed' => true,
        'confidential_allowed' => true,
    ],

    /*
    | Disaggregation. Sensitive dimensions are never mandatory and cross-tab
    | cells below the minimum are suppressed to prevent re-identification.
    */
    'disaggregation' => [
        'minimum_cell_size' => 5,
        'suppression_label' => '<5',
    ],

    /*
    | AI. The AI may propose; it never owns findings, resolution or closure.
    */
    'ai' => [
        'provider' => env('SASA_AI_PROVIDER', 'null'),
        'model' => env('SASA_AI_MODEL', 'claude-sonnet-5'),
        'timeout_seconds' => 30,
        'min_confidence_to_suggest' => 0.35,
        'never_owns' => ['investigation', 'resolution', 'corrective_action', 'closure'],
    ],

    'voice' => [
        'provider' => env('SASA_VOICE_PROVIDER', 'null'),
        'webhook_secret' => env('SASA_VOICE_WEBHOOK_SECRET'),
        /*
        | AMBIGUITY (blueprint §11.4): the two-minute cap has no defined
        | behaviour at the limit. Our default is option (b) — a SOFT cap: warn
        | the caller, offer to continue or arrange a call-back, and flag every
        | truncated call for human review.
        */
        'cap_seconds' => (int) env('SASA_VOICE_SOFT_CAP_SECONDS', 120),
        'cap_behaviour' => 'soft', // soft | hard | structured_only
        'warn_at_seconds' => 100,
        'extension_seconds' => 120,
        'consent_required' => true,
        'languages' => ['sw', 'en'],
    ],

    'sync' => [
        'max_batch_operations' => 200,
        'max_retries' => 8,
        'conflict_strategy' => 'last_write_wins_per_field',
        // Fields that must NEVER be silently overwritten by a late sync.
        'protected_fields' => [
            'grievance' => ['status', 'severity', 'resolution_summary', 'closed_at', 'assigned_to_id', 'confidentiality'],
            'commitment' => ['status', 'verification_status', 'completed_at'],
            'stakeholder' => ['status', 'priority'],
        ],
    ],

    'reports' => [
        'formats' => ['pdf', 'docx', 'xlsx', 'csv'],
        'retention_days' => 3650,
        'fiscal_year_start_month' => 1,
    ],

    'audit' => [
        'retain_days' => null, // append-only, never pruned by default
        'sensitive_view_actions' => ['grievance.sensitive_view', 'stakeholder.sensitive_view'],
    ],

    /*
    | PAP Master Register. Every dropdown list here is a default that the
    | configuration registry can override per organisation or project.
    */
    'pap' => [
        'currency' => 'TZS',

        // Part A — PAP profile
        'genders' => ['female' => 'Female', 'male' => 'Male'],
        'marital_statuses' => [
            'single' => 'Single', 'married' => 'Married', 'cohabiting' => 'Cohabiting',
            'widowed' => 'Widowed', 'divorced' => 'Divorced', 'separated' => 'Separated',
        ],
        'vulnerability_categories' => [
            'none' => 'None',
            'female_headed_household' => 'Female headed household',
            'elderly_person' => 'Elderly person',
            'person_with_disability' => 'Person with disability',
            'chronically_ill' => 'Chronically ill',
            'orphan_headed_household' => 'Orphan headed household',
            'extremely_poor_household' => 'Extremely poor household',
            'other_vulnerable_group' => 'Other vulnerable group',
        ],
        // How a PAP vulnerability category lands in the stakeholder register's
        // own vulnerability list when the PAP is synced across.
        'stakeholder_vulnerability_map' => [
            'female_headed_household' => 'female_headed_household',
            'elderly_person' => 'elderly',
            'person_with_disability' => 'disability',
            'chronically_ill' => 'chronically_ill',
            'orphan_headed_household' => 'child_headed_household',
            'extremely_poor_household' => 'low_income',
            'other_vulnerable_group' => 'other',
        ],
        // The priority assessment a PAP receives when it first enters the
        // stakeholder register. Directly affected, rarely powerful.
        'stakeholder_defaults' => [
            'influence' => 'low', 'interest' => 'high', 'power' => 'low', 'impact' => 'high',
        ],

        // Part B — household socio-economic survey
        'education_levels' => [
            'none' => 'No formal education', 'primary' => 'Primary', 'secondary' => 'Secondary',
            'vocational' => 'Vocational', 'tertiary' => 'College / university',
        ],
        'poverty_categories' => [
            'extremely_poor' => 'Extremely poor', 'poor' => 'Poor',
            'vulnerable_non_poor' => 'Vulnerable non-poor', 'non_poor' => 'Non-poor',
        ],
        'water_access' => [
            'piped' => 'Piped water', 'borehole' => 'Borehole', 'protected_well' => 'Protected well',
            'unprotected_source' => 'River, dam or unprotected well', 'none' => 'No reliable access',
        ],
        'sanitation_access' => [
            'flush_toilet' => 'Flush toilet', 'improved_pit' => 'Improved pit latrine',
            'traditional_pit' => 'Traditional pit latrine', 'none' => 'No facility',
        ],
        'electricity_access' => [
            'grid' => 'Grid connection', 'solar' => 'Solar', 'none' => 'None',
        ],

        // Part C — land inventory
        'land_ownership_types' => [
            'owner' => 'Owner', 'tenant' => 'Tenant', 'customary_occupant' => 'Customary occupant',
            'license_holder' => 'License holder', 'informal_occupant' => 'Informal occupant',
            'institution' => 'Institution',
        ],
        'land_uses' => [
            'residential' => 'Residential', 'agricultural' => 'Agricultural', 'commercial' => 'Commercial',
            'grazing' => 'Grazing', 'mixed' => 'Mixed use', 'institutional' => 'Institutional', 'vacant' => 'Vacant',
        ],
        'land_statuses' => [
            'surveyed' => 'Surveyed', 'valued' => 'Valued', 'compensated' => 'Compensated',
            'handed_over' => 'Handed over to project', 'disputed' => 'Disputed',
        ],

        // Part D — asset inventory
        'asset_categories' => [
            'residential_structure' => 'Residential structure', 'commercial_structure' => 'Commercial structure',
            'fence' => 'Fence', 'crop' => 'Crop', 'fruit_tree' => 'Fruit tree', 'timber_tree' => 'Timber tree',
            'water_source' => 'Water source', 'livestock_facility' => 'Livestock facility',
            'community_asset' => 'Community asset', 'utility_connection' => 'Utility connection', 'other' => 'Other',
        ],
        'asset_conditions' => ['good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor', 'under_construction' => 'Under construction'],
        'asset_affected_statuses' => [
            'fully_affected' => 'Fully affected', 'partially_affected' => 'Partially affected', 'not_affected' => 'Not affected',
        ],
        'asset_units' => ['m2' => 'm²', 'm' => 'metres', 'no' => 'number', 'ha' => 'hectares'],

        // Part E — crop and tree inventory
        'productive_statuses' => [
            'mature_productive' => 'Mature — productive', 'immature' => 'Immature',
            'declining' => 'Declining', 'not_productive' => 'Not productive',
        ],
        'crop_rate_bases' => ['per_plant' => 'Per plant / tree', 'per_hectare' => 'Per hectare'],

        // Part F — valuation register
        'valuation_approval_statuses' => [
            'draft' => 'Draft', 'submitted' => 'Submitted for approval', 'approved' => 'Approved',
            'rejected' => 'Rejected', 'under_review' => 'Under review',
        ],

        // Part G — entitlement register
        'entitlement_types' => [
            'cash_compensation' => 'Cash compensation', 'replacement_land' => 'Replacement land',
            'replacement_house' => 'Replacement house', 'moving_assistance' => 'Moving assistance',
            'livelihood_restoration' => 'Livelihood restoration', 'business_support' => 'Business support',
            'special_assistance' => 'Special assistance', 'training' => 'Training',
        ],
        'entitlement_statuses' => [
            'proposed' => 'Proposed', 'approved' => 'Approved', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled',
        ],

        // Part H — compensation payment register
        'payment_statuses' => [
            'pending' => 'Pending', 'partially_paid' => 'Partially paid', 'paid' => 'Paid',
            'on_hold' => 'On hold', 'disputed' => 'Disputed',
        ],
        'payment_methods' => [
            'bank_transfer' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'cheque' => 'Cheque', 'cash' => 'Cash',
        ],

        // Part I — relocation register
        'relocation_statuses' => [
            'not_required' => 'Not required', 'planned' => 'Planned', 'house_under_construction' => 'House under construction',
            'house_handed_over' => 'House handed over', 'in_transit' => 'In transit', 'relocated' => 'Relocated',
        ],

        // Part J — livelihood restoration
        'livelihood_statuses' => [
            'not_started' => 'Not started', 'active' => 'Active', 'completed' => 'Completed', 'needs_follow_up' => 'Needs follow up',
        ],
        'income_restored_options' => [
            'yes' => 'Yes — restored or improved', 'partially' => 'Partially', 'no' => 'Not yet',
        ],

        // Part K — grievances live in the grievance module; these are the
        // PAP issue categories for reference and for category set-up.
        'grievance_issue_categories' => [
            'valuation' => 'Valuation', 'boundary_dispute' => 'Boundary dispute', 'ownership_dispute' => 'Ownership dispute',
            'payment_delay' => 'Payment delay', 'compensation_amount' => 'Compensation amount', 'relocation' => 'Relocation',
            'livelihood_restoration' => 'Livelihood restoration', 'conduct_of_staff' => 'Conduct of staff', 'other' => 'Other',
        ],

        // Part L — consultation register
        'meeting_types' => [
            'village_assembly' => 'Village assembly', 'focus_group' => 'Focus group discussion',
            'household_meeting' => 'Household meeting', 'public_hearing' => 'Public hearing',
            'valuation_disclosure' => 'Valuation disclosure', 'grievance_committee' => 'Grievance committee',
            'other' => 'Other',
        ],

        // Part M — document register
        'document_types' => [
            'nida_card' => 'NIDA card', 'ccro' => 'CCRO', 'title_deed' => 'Title deed', 'valuation_form' => 'Valuation form',
            'compensation_agreement' => 'Compensation agreement', 'bank_confirmation' => 'Bank confirmation',
            'marriage_certificate' => 'Marriage certificate', 'death_certificate' => 'Death certificate',
            'power_of_attorney' => 'Power of attorney', 'photographs' => 'Photographs', 'other' => 'Other',
        ],
        // A document number of these types IS an identity or bank number, so it
        // is treated like one: absent unless the viewer has pap.view_sensitive.
        'sensitive_document_types' => ['nida_card', 'bank_confirmation'],
    ],

    /*
    | ESHS contractor assurance. Contractor and subcontractor lists are empty
    | by default: each project configures its own.
    */
    'eshs' => [
        // Configured per project (Settings on the ESHS page). Empty here on purpose.
        'contractors' => [],
        'subcontractors' => [],

        // ESHS category => its subcategories. Keys are stored; labels shown.
        'categories' => [
            'environment' => [
                'label' => 'Environment',
                'subcategories' => [
                    'Waste Management', 'Hazardous Waste', 'Spill / Leakage', 'Air Emissions', 'Dust',
                    'Noise & Vibration', 'Water Pollution', 'Water Consumption', 'Soil Contamination',
                    'Biodiversity', 'Wildlife', 'Vegetation Clearing', 'Erosion & Sedimentation',
                    'Chemical Storage', 'Fuel Storage', 'Resource Efficiency', 'Wastewater Management',
                    'Environmental Permit Non-Compliance',
                ],
            ],
            'safety' => [
                'label' => 'Safety',
                'subcategories' => [
                    'Unsafe Act', 'Unsafe Condition', 'Work at Height', 'Excavation / Trenching',
                    'Lifting Operations', 'Confined Space', 'Electrical Safety', 'Lockout / Tagout (LOTO)',
                    'Machine Guarding', 'Mobile Equipment', 'Traffic Management', 'PPE Non-Compliance',
                    'Fire Safety', 'Housekeeping', 'Falling Objects', 'Hot Work', 'Scaffolding',
                    'Permit to Work Violation', 'Emergency Preparedness', 'Dropped Object', 'Near Miss',
                ],
            ],
            'health' => [
                'label' => 'Health',
                'subcategories' => [
                    'Occupational Illness', 'Exposure to Noise', 'Exposure to Dust', 'Exposure to Chemicals',
                    'Ergonomics', 'Fatigue Management', 'Heat Stress', 'Hygiene & Sanitation',
                    'Drinking Water Quality', 'Medical Emergency', 'Mental Wellbeing', 'Vector-Borne Diseases',
                    'Food Safety', 'Health Surveillance Non-Compliance',
                ],
            ],
            'social' => [
                'label' => 'Social',
                'subcategories' => [
                    'Community Complaint', 'Land Access Issue', 'Grievance Management', 'Stakeholder Engagement',
                    'Security Incident', 'Labor Rights', 'Worker Welfare', 'Gender-Based Violence (GBV) Concern',
                    'Child Protection Concern', 'Cultural Heritage', 'Local Content Commitment',
                    'Community Health & Safety', 'Traffic Impact on Communities', 'Resettlement',
                    'Livelihood Issue', 'Human Rights Concern',
                ],
            ],
        ],

        'anomaly_types' => [
            'Observation', 'Non-Conformance', 'Near Miss', 'Incident', 'Regulatory Breach',
            'Community Grievance', 'Improvement / Opportunity', 'Audit Finding', 'Inspection Finding',
            'Worker Grievance',
        ],

        // Stored key => shown label. Critical = danger, High = orange.
        'risk_ratings' => [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical',
        ],

        // Root cause heading => causes. Both the heading and the cause are stored.
        'root_causes' => [
            'People' => [
                'Inadequate Training / Competency', 'Lack of Awareness', 'Lack of Supervision',
                'Human Error', 'Fatigue', 'Failure to Follow Procedure',
            ],
            'Planning & Management' => [
                'Inadequate Planning', 'Inadequate Risk Assessment', 'Inadequate Procedure / SOP',
                'Poor Communication', 'Inadequate Monitoring / Inspection', 'Contractor Management Failure',
                'Subcontractor Management Failure', 'Change Management Failure', 'Management System Failure',
            ],
            'Resources & Equipment' => [
                'Inadequate Resources', 'Inadequate Staffing', 'Equipment Failure', 'Poor Maintenance',
                'Inadequate Tools / Equipment', 'PPE Unavailable or Inadequate',
            ],
            'Environmental' => [
                'Dust Control Failure', 'Waste Management Failure', 'Pollution / Spill Control Failure',
                'Erosion & Sediment Control Failure', 'Biodiversity Protection Failure',
                'Noise & Vibration Control Failure', 'Weather / Natural Conditions',
            ],
            'Social' => [
                'Stakeholder Engagement Failure', 'Community Communication Failure', 'Grievance Management Failure',
                'Land Access / Resettlement Issue', 'Community Traffic Management Failure',
                'Security Management Failure', 'Labor Rights / Worker Welfare Failure', 'Human Rights Concern',
                'GBV/SEA/SH Control Failure',
            ],
            'Health' => [
                'Occupational Hygiene Failure', 'Heat Stress Management Failure',
                'Drinking Water / Sanitation Failure', 'Medical Emergency Preparedness Failure',
                'Disease Prevention Failure', 'Health Surveillance Failure',
            ],
            'Other' => [
                'Design / Engineering Deficiency', 'Regulatory non-compliance', 'Third-Party Action', 'Other',
            ],
        ],
    ],

    /*
    | Incident notification. Companies, contractor managers and resident
    | engineers are configured per project.
    */
    'incidents' => [
        // Per-project names lists. Empty by default: each project sets its own
        // through the configuration registry (keys incident_companies,
        // incident_contractor_managers, incident_resident_engineers).
        'companies' => [],
        'contractor_managers' => [],
        'resident_engineers' => [],

        // The client's Incident Notification Report, exactly as written.
        'categories' => [
            ['key' => 'near_miss', 'label' => 'Near miss'],
            ['key' => 'personal_injury', 'label' => 'Incident – Personal Injury'],
            ['key' => 'environmental', 'label' => 'Incident – Environmental'],
            ['key' => 'materials_loss', 'label' => 'Incident – Materials Loss'],
            ['key' => 'security', 'label' => 'Incident – Security'],
            ['key' => 'social', 'label' => 'Incident – Social'],
        ],

        // `category` is the category this type naturally belongs to, used only
        // to put the likely types first on the form. Any type may be chosen
        // under any category. `serious` flags the type as serious outright.
        'types' => [
            ['key' => 'hs_fatality', 'label' => 'Health & Safety - Fatality (FAT)', 'category' => 'personal_injury', 'serious' => true],
            ['key' => 'hs_permanent_disability', 'label' => 'Health & Safety - Permanent Disability', 'category' => 'personal_injury', 'serious' => true],
            ['key' => 'hs_lti', 'label' => 'Health & Safety - Lost Time Injury (LTI)', 'category' => 'personal_injury'],
            ['key' => 'hs_rwdc', 'label' => 'Health & Safety - Restricted Workday Case (RWDC)', 'category' => 'personal_injury'],
            ['key' => 'hs_mtc', 'label' => 'Health & Safety - Medical Treatment Case (MTC)', 'category' => 'personal_injury'],
            ['key' => 'hs_fac', 'label' => 'Health & Safety - First Aid Case (FAC)', 'category' => 'personal_injury'],
            ['key' => 'hs_oi', 'label' => 'Health & Safety - Occupational illness (OI)', 'category' => 'personal_injury'],
            ['key' => 'hs_nwri', 'label' => 'Health & Safety - Non-Work-Related Injury/illness (NWRI)', 'category' => 'personal_injury'],
            ['key' => 'hs_third_party_injury', 'label' => 'Health & Safety - External "(3rd Party Injury)"', 'category' => 'personal_injury'],
            ['key' => 'env_permit_breach', 'label' => 'Environmental - Consent level Exceeded / Conditions of Permit Breached', 'category' => 'environmental'],
            ['key' => 'env_biodiversity_damage', 'label' => 'Environmental - Damage to Biodiversity', 'category' => 'environmental'],
            ['key' => 'env_waste_management', 'label' => 'Environmental - Incorrect Waste Management / Disposal', 'category' => 'environmental'],
            ['key' => 'env_radioactive_loss', 'label' => 'Environmental - Loss of Radioactive Material', 'category' => 'environmental'],
            ['key' => 'env_lopc', 'label' => 'Environmental - Loss of Primary containment (LOPC)', 'category' => 'environmental'],
            ['key' => 'env_spill_hydrocarbon', 'label' => 'Environmental - Spill - Hydrocarbon', 'category' => 'environmental'],
            ['key' => 'env_spill_chemical', 'label' => 'Environmental - Spill - Chemical', 'category' => 'environmental'],
            ['key' => 'env_spill_other', 'label' => 'Environmental - Spill - Other', 'category' => 'environmental'],
            ['key' => 'ml_air_transport', 'label' => 'Material Loss - Air Transport Incident', 'category' => 'materials_loss'],
            ['key' => 'ml_dropped_objects', 'label' => 'Material Loss - Dropped Objects (DO)', 'category' => 'materials_loss'],
            ['key' => 'ml_fire_explosion', 'label' => 'Material Loss - Fire / Explosion', 'category' => 'materials_loss'],
            ['key' => 'ml_lifting', 'label' => 'Material Loss - Lifting Related Incident (LRI)', 'category' => 'materials_loss'],
            ['key' => 'ml_lopc', 'label' => 'Material Loss - Loss of Primary Containment (LOPC)', 'category' => 'materials_loss'],
            ['key' => 'ml_marine', 'label' => 'Material Loss - Marine Incident', 'category' => 'materials_loss'],
            ['key' => 'ml_mechanical_failure', 'label' => 'Material Loss - Mechanical Failure', 'category' => 'materials_loss'],
            ['key' => 'ml_material_damage', 'label' => 'Material Loss - Material Damage', 'category' => 'materials_loss'],
            ['key' => 'ml_road_traffic', 'label' => 'Material Loss - Road Traffic Accident', 'category' => 'materials_loss'],
            ['key' => 'ml_serious_road_traffic', 'label' => 'Material Loss - Serious Road Traffic Accident (SRTA)', 'category' => 'materials_loss'],
            ['key' => 'near_miss', 'label' => 'Near Miss (NM)', 'category' => 'near_miss'],
            ['key' => 'sec_assault', 'label' => 'Security - Assault', 'category' => 'security'],
            ['key' => 'sec_cyber_breach', 'label' => 'Security - Cyber Security Breach', 'category' => 'security'],
            ['key' => 'sec_espionage', 'label' => 'Security - Espionage', 'category' => 'security'],
            ['key' => 'sec_terrorism', 'label' => 'Security - Terrorism', 'category' => 'security'],
            ['key' => 'sec_theft', 'label' => 'Security - Theft', 'category' => 'security'],
            ['key' => 'industrial_action', 'label' => 'Industrial Action', 'category' => null],
            ['key' => 'ir_sexual_harassment_community', 'label' => 'Industrial Relations - Sexual Harassment to Community Member', 'category' => null],
            ['key' => 'ir_code_of_conduct_breach', 'label' => 'Industrial Relations - Worker Breach of Code of Conduct', 'category' => null],
            ['key' => 'ir_conditions_of_employment', 'label' => 'Industrial Relations - Industrial Relations-Condition of Employment', 'category' => null],
            ['key' => 'soc_cultural_heritage_damage', 'label' => 'Social - Cultural Heritage damage', 'category' => 'social'],
            ['key' => 'soc_unauthorised_land_access', 'label' => 'Social - Unauthorised access or damage to private / community land', 'category' => 'social'],
            ['key' => 'soc_asset_damage', 'label' => 'Social - Private / community asset damage', 'category' => 'social'],
            ['key' => 'soc_local_workforce_health', 'label' => 'Social- Local workforce community health', 'category' => 'social'],
            ['key' => 'soc_non_local_workforce_impacts', 'label' => 'Social - non-local workforce community impacts', 'category' => 'social'],
            ['key' => 'soc_assault_community_member', 'label' => 'Social - Assault on Community Member', 'category' => 'social'],
            ['key' => 'soc_community_assets_loss', 'label' => 'Social - Damage to or Loss of Community Assets', 'category' => 'social'],
            ['key' => 'soc_damage_cultural_heritage', 'label' => 'Social - Damage to Cultural Heritage', 'category' => 'social'],
            ['key' => 'non_work_related_death', 'label' => 'Non-Work-Related Death', 'category' => null],
        ],

        'consequence_levels' => [
            0 => 'Level 0: Near Miss',
            1 => 'Level 1: Minor',
            2 => 'Level 2: Moderate',
            3 => 'Level 3: Significant',
            4 => 'Level 4: Severe',
            5 => 'Level 5: Catastrophic',
        ],

        // An incident is "serious" when its potential consequence reaches this
        // level, or its type is flagged serious above.
        'serious_potential_level' => 4,
    ],
];
