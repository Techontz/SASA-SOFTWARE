<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use Archivable, Auditable, HasFactory, TracksAuthor;

    public const AUDIT_NAME = 'project';

    protected $fillable = [
        'organisation_id', 'name', 'code', 'description', 'country', 'sector',
        'timezone', 'status', 'starts_on', 'ends_on', 'fiscal_year_start_month', 'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'archived_at' => 'datetime',
            'fiscal_year_start_month' => 'integer',
        ];
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function memberships()
    {
        return $this->hasMany(ProjectMembership::class);
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'project_memberships')
            ->withPivot(['role_id', 'status', 'handling_groups'])
            ->withTimestamps();
    }

    public function stakeholders()
    {
        return $this->hasMany(Stakeholder::class);
    }

    public function grievances()
    {
        return $this->hasMany(Grievance::class);
    }

    public function engagements()
    {
        return $this->hasMany(Engagement::class);
    }

    public function commitments()
    {
        return $this->hasMany(Commitment::class);
    }

    public function locations()
    {
        return $this->hasMany(Location::class);
    }

    public function configurations()
    {
        return $this->hasMany(Configuration::class);
    }

    public function workingCalendars()
    {
        return $this->hasMany(WorkingCalendar::class);
    }
}
