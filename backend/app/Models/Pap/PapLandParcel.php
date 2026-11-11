<?php

namespace App\Models\Pap;

use App\Models\Concerns\HasReference;

class PapLandParcel extends PapRecord
{
    use HasReference;

    public const REFERENCE_KEY = 'pap_land';

    public const AUDIT_NAME = 'pap_land';

    public const INPUT_FIELDS = [
        'plot_number', 'village', 'land_use', 'ownership_type', 'ccro_number', 'title_number',
        'total_plot_size_ha', 'affected_area_ha', 'gps_easting', 'gps_northing', 'map_reference',
        'land_status', 'remarks',
    ];

    public const DERIVED_FIELDS = ['percentage_affected'];

    public const SENSITIVE_FIELDS = [];

    protected $table = 'pap_land_parcels';

    protected function casts(): array
    {
        return [
            'total_plot_size_ha' => 'decimal:4',
            'affected_area_ha' => 'decimal:4',
            'percentage_affected' => 'decimal:2',
            'gps_easting' => 'decimal:3',
            'gps_northing' => 'decimal:3',
            'archived_at' => 'datetime',
        ];
    }
}
