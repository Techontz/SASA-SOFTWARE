<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * ENQ-2026-0001: a "Register interest", "Book a demo" or "Request a quote" form from the public
 * website. Made by the public, so it is addressed in URLs by an unguessable
 * ULID, never by its numeric id.
 */
class Enquiry extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'enquiry';

    public const TYPES = ['interest', 'demo', 'quote'];

    public const SECTORS = ['infrastructure', 'mining', 'energy', 'other'];

    public const STATUSES = ['new', 'contacted', 'closed'];

    public const MODULES = [
        'stakeholder_register', 'stakeholder_engagement', 'grievance_management',
        'pap_register', 'incident_notification', 'eshs_assurance',
        'dashboards_reporting', 'not_sure',
    ];

    /** Qualifying answers on a quote request, stored in `details`. */
    public const COMPANY_SIZES = ['1-50', '51-200', '201-1000', '1000+'];

    public const PROJECT_STAGES = ['planning', 'construction', 'operation', 'mixed'];

    public const START_TIMEFRAMES = ['now', '1-3_months', '3-6_months', 'later'];

    public const DETAIL_KEYS = ['company_size', 'projects_count', 'users_count', 'project_location', 'project_stage', 'start_timeframe'];

    /** The abuse-review hash says nothing useful in an audit row. */
    protected array $auditExcluded = ['ip_hash'];

    protected $fillable = [
        'type', 'full_name', 'email', 'organization', 'sector', 'phone', 'modules', 'details', 'message',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'details' => 'array',
            'handled_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
