<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AI proposals live HERE, never in the confirmed columns. The case shows
 * "AI-suggested — confirm" until a human accepts or changes it, which makes
 * classification accuracy measurable and keeps accountability with the officer.
 */
class AiSuggestion extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'subject_type', 'subject_id', 'kind',
        'suggestion', 'confidence', 'provider', 'model', 'raw_response',
        'status', 'accepted_value', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'suggestion' => 'array',
            'raw_response' => 'array',
            'accepted_value' => 'array',
            'confidence' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
