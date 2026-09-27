<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A 24/7 AI intake call. Consent is mandatory: where consent is denied we log
 * "Consent Not Granted", route to the human call-back queue, and never create
 * a grievance.
 */
class VoiceCall extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'provider', 'external_id', 'caller_number',
        'language', 'status', 'consent_granted', 'consent_at', 'consent_statement',
        'duration_seconds', 'was_truncated', 'extension_offered', 'extension_accepted',
        'needs_human_review', 'callback_requested', 'audio_path', 'transcript', 'turns',
        'ai_metadata', 'grievance_id', 'started_at', 'ended_at',
    ];

    protected $hidden = ['caller_number_hash', 'audio_path'];

    protected function casts(): array
    {
        return [
            'caller_number' => 'encrypted',
            'consent_granted' => 'boolean',
            'was_truncated' => 'boolean',
            'extension_offered' => 'boolean',
            'extension_accepted' => 'boolean',
            'needs_human_review' => 'boolean',
            'callback_requested' => 'boolean',
            'turns' => 'array',
            'ai_metadata' => 'array',
            'consent_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (VoiceCall $call) {
            if ($call->isDirty('caller_number')) {
                $call->caller_number_hash = Grievance::blindIndex(
                    Grievance::normalisePhone($call->caller_number)
                );
            }
        });
    }

    public function grievance()
    {
        return $this->belongsTo(Grievance::class);
    }
}
