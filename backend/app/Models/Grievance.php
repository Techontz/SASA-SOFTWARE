<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasReference;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Config;

/**
 * GRV-0001 — one unified case, whatever channel it arrived through.
 *
 * Complainant identity is encrypted at rest with a deterministic blind index
 * for exact-match search. An ANONYMOUS case stores no identity at all; a
 * CONFIDENTIAL case stores it but releases it only to the handling group.
 */
class Grievance extends Model
{
    use Archivable, Auditable, BelongsToTenant, HasFactory, HasReference, TracksAuthor;

    public const AUDIT_NAME = 'grievance';

    public const REFERENCE_KEY = 'grievance';

    public const CHANNELS = [
        'voice', 'whatsapp', 'sms', 'web', 'in_person', 'email', 'leader', 'suggestion_box',
    ];

    public const STATUSES = [
        'new', 'classified', 'assigned', 'acknowledged', 'under_investigation',
        'action_pending', 'resolved', 'awaiting_confirmation', 'closed',
        'reopened', 'rejected', 'withdrawn',
    ];

    public const OPEN_STATUSES = [
        'new', 'classified', 'assigned', 'acknowledged', 'under_investigation',
        'action_pending', 'awaiting_confirmation', 'reopened',
    ];

    public const CLOSED_STATUSES = ['closed', 'rejected', 'withdrawn'];

    /** Fields only the handling group may ever receive. */
    public const SENSITIVE_FIELDS = [
        'complainant_name', 'complainant_phone', 'complainant_email',
        'complainant_address', 'precise_location', 'latitude', 'longitude',
        'stakeholder_id',
    ];

    protected $fillable = [
        'organisation_id', 'project_id', 'client_uuid', 'idempotency_key', 'channel',
        'channel_reference', 'received_at', 'occurred_at', 'received_by',
        'confidentiality', 'complainant_type', 'complainant_name', 'complainant_phone',
        'complainant_email', 'complainant_address', 'complainant_language',
        'preferred_contact_method', 'stakeholder_id', 'location_id', 'location_text',
        'precise_location', 'latitude', 'longitude', 'category_id', 'subcategory_id',
        'severity', 'title', 'description', 'desired_resolution', 'demographics',
        'custom_fields', 'source_concern_id', 'voice_call_id', 'captured_at', 'synced_at',
        'acknowledgement_possible', 'acknowledgement_not_possible_reason',
    ];

    protected $hidden = ['complainant_name_hash', 'complainant_phone_hash'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'occurred_at' => 'datetime',
            'classified_at' => 'datetime',
            'assigned_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'investigation_started_at' => 'datetime',
            'investigation_completed_at' => 'datetime',
            'corrective_action_due' => 'date',
            'resolved_at' => 'datetime',
            'complainant_responded_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_reopened_at' => 'datetime',
            'escalated_at' => 'datetime',
            'acknowledgement_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'captured_at' => 'datetime',
            'synced_at' => 'datetime',
            'archived_at' => 'datetime',
            'demographics' => 'array',
            'custom_fields' => 'array',
            'handling_groups' => 'array',
            'is_restricted' => 'boolean',
            'classification_confirmed' => 'boolean',
            'acknowledgement_possible' => 'boolean',
            'has_ai_suggestions' => 'boolean',
            'severity' => 'integer',
            'resolution_cycle' => 'integer',
            'reopen_count' => 'integer',
            'escalation_level' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            // Encrypted at rest. Blind-index columns carry the searchable form.
            'complainant_name' => 'encrypted',
            'complainant_phone' => 'encrypted',
            'complainant_email' => 'encrypted',
            'complainant_address' => 'encrypted',
            'precise_location' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Grievance $grievance) {
            // An anonymous case never stores identity — there is nothing to leak.
            if ($grievance->confidentiality === 'anonymous') {
                foreach (['complainant_name', 'complainant_phone', 'complainant_email', 'complainant_address'] as $field) {
                    $grievance->{$field} = null;
                }
                $grievance->stakeholder_id = null;
                $grievance->complainant_name_hash = null;
                $grievance->complainant_phone_hash = null;

                return;
            }

            if ($grievance->isDirty('complainant_name')) {
                $grievance->complainant_name_hash = static::blindIndex($grievance->complainant_name);
            }

            if ($grievance->isDirty('complainant_phone')) {
                $grievance->complainant_phone_hash = static::blindIndex(
                    static::normalisePhone($grievance->complainant_phone)
                );
            }
        });
    }

    /** Deterministic keyed hash — searchable, but useless without the app key. */
    public static function blindIndex(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return hash_hmac('sha256', mb_strtolower(trim($value)), Config::get('app.key'));
    }

    public static function normalisePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return $digits === '' ? null : substr($digits, -9);
    }

    // ---------------------------------------------------------------- relations

    public function category()
    {
        return $this->belongsTo(GrievanceCategory::class, 'category_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(GrievanceCategory::class, 'subcategory_id');
    }

    public function stakeholder()
    {
        return $this->belongsTo(Stakeholder::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class)->orderByDesc('assigned_at');
    }

    public function followUps()
    {
        return $this->hasMany(GrievanceFollowUp::class)->orderBy('created_at');
    }

    public function cycles()
    {
        return $this->hasMany(GrievanceResolutionCycle::class)->orderBy('cycle_number');
    }

    public function escalations()
    {
        return $this->hasMany(GrievanceEscalation::class)->orderByDesc('escalated_at');
    }

    public function communications()
    {
        return $this->hasMany(Communication::class)->orderByDesc('created_at');
    }

    public function commitments()
    {
        return $this->hasMany(Commitment::class);
    }

    public function sourceConcern()
    {
        return $this->belongsTo(Concern::class, 'source_concern_id');
    }

    public function voiceCall()
    {
        return $this->belongsTo(VoiceCall::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function aiSuggestions(): MorphMany
    {
        return $this->morphMany(AiSuggestion::class, 'subject');
    }

    public function slaClocks(): MorphMany
    {
        return $this->morphMany(SlaClock::class, 'subject');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ------------------------------------------------------------------ scopes

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('status', self::CLOSED_STATUSES);
    }

    public function scopeHighSeverity(Builder $query): Builder
    {
        return $query->where('severity', '>=', config('sasa.grievances.management_alert_from_severity', 4));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';
        $phoneHash = static::blindIndex(static::normalisePhone($term));

        return $query->where(function (Builder $q) use ($like, $term, $phoneHash) {
            $q->where('reference', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('location_text', 'like', $like);

            // Exact phone match via the blind index — never a decrypt-and-scan.
            if ($phoneHash && preg_match('/\d{6,}/', $term)) {
                $q->orWhere('complainant_phone_hash', $phoneHash);
            }

            $nameHash = static::blindIndex($term);
            if ($nameHash) {
                $q->orWhere('complainant_name_hash', $nameHash);
            }
        });
    }

    // ------------------------------------------------------------------- state

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isAnonymous(): bool
    {
        return $this->confidentiality === 'anonymous';
    }

    public function isConfidential(): bool
    {
        return $this->confidentiality === 'confidential' || $this->is_restricted;
    }

    public function currentCycle(): ?GrievanceResolutionCycle
    {
        return $this->cycles()->where('cycle_number', $this->resolution_cycle)->first();
    }

    public function severityLabel(): string
    {
        return config("sasa.grievances.severity_levels.{$this->severity}.label", 'Not yet assessed');
    }

    public function daysOpen(): int
    {
        $end = $this->closed_at ?? now();

        return (int) $this->received_at->diffInDays($end);
    }
}
