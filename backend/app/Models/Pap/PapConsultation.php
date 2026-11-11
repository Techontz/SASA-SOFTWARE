<?php

namespace App\Models\Pap;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasReference;
use App\Models\Concerns\TracksAuthor;
use App\Models\Location;
use Illuminate\Database\Eloquent\Model;

/**
 * CNS-0001 — Part L. A resettlement consultation is held with a village, not
 * with one PAP, so it belongs to the project rather than to a PAP record.
 */
class PapConsultation extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasReference, TracksAuthor;

    public const AUDIT_NAME = 'pap_consultation';

    public const REFERENCE_KEY = 'pap_consultation';

    public const INPUT_FIELDS = [
        'consultation_date', 'location_id', 'village', 'meeting_type', 'topic_discussed', 'facilitator',
        'number_male', 'number_female', 'number_vulnerable', 'attendance_sheet_reference',
        'key_concerns_raised', 'actions_agreed',
    ];

    protected $fillable = [
        'organisation_id', 'project_id', 'consultation_date', 'location_id', 'village', 'meeting_type',
        'topic_discussed', 'facilitator', 'number_male', 'number_female', 'number_vulnerable',
        'attendance_sheet_reference', 'key_concerns_raised', 'actions_agreed',
    ];

    protected function casts(): array
    {
        return [
            'consultation_date' => 'date',
            'number_male' => 'integer',
            'number_female' => 'integer',
            'number_vulnerable' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function totalAttendance(): int
    {
        return (int) $this->number_male + (int) $this->number_female;
    }
}
