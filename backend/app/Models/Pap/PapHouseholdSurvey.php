<?php

namespace App\Models\Pap;

class PapHouseholdSurvey extends PapRecord
{
    public const AUDIT_NAME = 'pap_household_survey';

    public const INPUT_FIELDS = [
        'household_id', 'survey_date', 'household_head', 'household_size', 'number_of_adults',
        'number_of_children', 'education_level', 'primary_occupation', 'secondary_occupation',
        'monthly_household_income', 'main_income_source', 'asset_ownership_score', 'poverty_category',
        'livestock_owned', 'access_to_electricity', 'access_to_water', 'access_to_sanitation', 'comments',
    ];

    public const DERIVED_FIELDS = [];

    public const SENSITIVE_FIELDS = [];

    protected $table = 'pap_household_surveys';

    protected function casts(): array
    {
        return [
            'survey_date' => 'date',
            'household_size' => 'integer',
            'number_of_adults' => 'integer',
            'number_of_children' => 'integer',
            'monthly_household_income' => 'decimal:2',
            'asset_ownership_score' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }
}
