<?php

namespace App\Models\Pap;

use App\Models\Concerns\HasReference;

class PapCrop extends PapRecord
{
    use HasReference;

    public const REFERENCE_KEY = 'pap_crop';

    public const AUDIT_NAME = 'pap_crop';

    public const INPUT_FIELDS = [
        'plot_number', 'crop_type', 'number_of_plants', 'area_cultivated', 'tree_species', 'tree_age',
        'productive_status', 'rate_basis', 'unit_rate', 'remarks',
    ];

    public const DERIVED_FIELDS = ['compensation_amount'];

    public const SENSITIVE_FIELDS = [];

    protected $table = 'pap_crops';

    protected function casts(): array
    {
        return [
            'number_of_plants' => 'integer',
            'area_cultivated' => 'decimal:4',
            'tree_age' => 'integer',
            'unit_rate' => 'decimal:2',
            'compensation_amount' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }
}
