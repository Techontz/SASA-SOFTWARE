<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasReference;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A concern raised in an engagement is a first-class record — and the thing a
 * grievance is escalated FROM, without anyone retyping it.
 */
class Concern extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasFactory, HasReference, TracksAuthor;

    public const AUDIT_NAME = 'concern';

    public const REFERENCE_KEY = 'concern';

    protected $fillable = [
        'organisation_id', 'project_id', 'client_uuid', 'engagement_id', 'stakeholder_id',
        'location_id', 'grievance_category_id', 'title', 'description', 'raised_by',
        'raised_on', 'severity_hint', 'status', 'response', 'owner_id',
        'captured_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'raised_on' => 'date',
            'escalated_at' => 'datetime',
            'captured_at' => 'datetime',
            'synced_at' => 'datetime',
            'archived_at' => 'datetime',
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

    public function category()
    {
        return $this->belongsTo(GrievanceCategory::class, 'grievance_category_id');
    }

    public function grievance()
    {
        return $this->belongsTo(Grievance::class);
    }

    public function commitments()
    {
        return $this->hasMany(Commitment::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
