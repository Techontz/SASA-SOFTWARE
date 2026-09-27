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
];
