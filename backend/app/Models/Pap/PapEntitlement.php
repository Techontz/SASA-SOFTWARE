<?php

namespace App\Models\Pap;

use App\Models\Concerns\HasReference;

class PapEntitlement extends PapRecord
{
    use HasReference;

    public const REFERENCE_KEY = 'pap_entitlement';

    public const AUDIT_NAME = 'pap_entitlement';

    public const INPUT_FIELDS = [
        'entitlement_type', 'description', 'quantity', 'unit_rate', 'approval_date',
        'entitlement_status', 'remarks',
    ];

    public const DERIVED_FIELDS = ['value'];

    public const SENSITIVE_FIELDS = [];

    protected $table = 'pap_entitlements';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_rate' => 'decimal:2',
            'value' => 'decimal:2',
            'approval_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }
}
