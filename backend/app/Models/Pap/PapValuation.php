<?php

namespace App\Models\Pap;

use App\Models\Concerns\HasReference;

class PapValuation extends PapRecord
{
    use HasReference;

    public const REFERENCE_KEY = 'pap_valuation';

    public const AUDIT_NAME = 'pap_valuation';

    public const INPUT_FIELDS = [
        'valuation_date', 'valuer_name', 'valuation_report_number', 'land_value', 'structure_value',
        'crop_value', 'tree_value', 'business_loss', 'disturbance_allowance', 'transport_allowance',
        'accommodation_allowance', 'loss_of_profit_allowance', 'other_allowances', 'approval_status',
        'approval_date',
    ];

    public const DERIVED_FIELDS = ['total_compensation_value'];

    public const SENSITIVE_FIELDS = [];

    protected $table = 'pap_valuations';

    /** The components summed into the total compensation value. */
    public const COMPONENTS = [
        'land_value', 'structure_value', 'crop_value', 'tree_value', 'business_loss',
        'disturbance_allowance', 'transport_allowance', 'accommodation_allowance',
        'loss_of_profit_allowance', 'other_allowances',
    ];

    protected function casts(): array
    {
        return [
            'valuation_date' => 'date',
            'approval_date' => 'date',
            'land_value' => 'decimal:2',
            'structure_value' => 'decimal:2',
            'crop_value' => 'decimal:2',
            'tree_value' => 'decimal:2',
            'business_loss' => 'decimal:2',
            'disturbance_allowance' => 'decimal:2',
            'transport_allowance' => 'decimal:2',
            'accommodation_allowance' => 'decimal:2',
            'loss_of_profit_allowance' => 'decimal:2',
            'other_allowances' => 'decimal:2',
            'total_compensation_value' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }
}
