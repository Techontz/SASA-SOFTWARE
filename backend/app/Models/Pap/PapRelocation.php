<?php

namespace App\Models\Pap;

use App\Models\Concerns\HasReference;

class PapRelocation extends PapRecord
{
    use HasReference;

    public const REFERENCE_KEY = 'pap_relocation';

    public const AUDIT_NAME = 'pap_relocation';

    public const INPUT_FIELDS = [
        'relocation_required', 'replacement_plot_number', 'replacement_house_number',
        'house_handover_date', 'moving_date', 'moving_assistance_provided',
        'transit_assistance_provided', 'relocation_status', 'remarks',
    ];

    public const DERIVED_FIELDS = [];

    public const SENSITIVE_FIELDS = [];

    protected $table = 'pap_relocations';

    protected function casts(): array
    {
        return [
            'relocation_required' => 'boolean',
            'house_handover_date' => 'date',
            'moving_date' => 'date',
            'moving_assistance_provided' => 'boolean',
            'transit_assistance_provided' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }
}
