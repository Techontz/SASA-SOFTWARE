<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EngagementParticipant extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'engagement_id', 'stakeholder_id', 'name',
        'category', 'organisation_name', 'position', 'phone', 'is_vulnerable',
        'demographics', 'signed_attendance',
    ];

    protected function casts(): array
    {
        return [
            'demographics' => 'array',
            'is_vulnerable' => 'boolean',
            'signed_attendance' => 'boolean',
        ];
    }

    public function engagement()
    {
        return $this->belongsTo(Engagement::class);
    }

    public function stakeholder()
    {
        return $this->belongsTo(Stakeholder::class);
    }
}
