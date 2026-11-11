<?php

namespace App\Models\Pap;

use App\Models\Concerns\HasReference;

class PapLivelihood extends PapRecord
{
    use HasReference;

    public const REFERENCE_KEY = 'pap_livelihood';

    public const AUDIT_NAME = 'pap_livelihood';

    public const INPUT_FIELDS = [
        'income_source_lost', 'livelihood_program', 'training_provided', 'grant_amount',
        'business_startup_support', 'agricultural_support', 'date_enrolled', 'date_completed',
        'monitoring_visits', 'income_restored', 'programme_status',
    ];

    public const DERIVED_FIELDS = [];

    public const SENSITIVE_FIELDS = [];

    protected $table = 'pap_livelihoods';

    protected function casts(): array
    {
        return [
            'grant_amount' => 'decimal:2',
            'date_enrolled' => 'date',
            'date_completed' => 'date',
            'monitoring_visits' => 'array',
            'archived_at' => 'datetime',
        ];
    }
}
