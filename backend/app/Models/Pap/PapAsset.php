<?php

namespace App\Models\Pap;

use App\Models\Concerns\HasReference;

class PapAsset extends PapRecord
{
    use HasReference;

    public const REFERENCE_KEY = 'pap_asset';

    public const AUDIT_NAME = 'pap_asset';

    public const INPUT_FIELDS = [
        'asset_category', 'asset_type', 'description', 'length', 'width', 'area', 'quantity', 'unit',
        'condition', 'affected_status', 'photo_reference', 'remarks',
    ];

    public const DERIVED_FIELDS = [];

    public const SENSITIVE_FIELDS = [];

    protected $table = 'pap_assets';

    protected function casts(): array
    {
        return [
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'area' => 'decimal:2',
            'quantity' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }
}
