<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A running clock on one subject. Pauses are recorded WITH a reason and are
 * reported — otherwise the pause mechanism becomes a way to make breaches
 * disappear.
 */
class SlaClock extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'subject_type', 'subject_id', 'clock', 'cycle',
        'sla_policy_id', 'started_at', 'due_at', 'completed_at', 'breached_at', 'state',
        'paused_at', 'pause_reason', 'paused_seconds_total', 'pause_history',
        'reminders_sent', 'target_value', 'unit',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'breached_at' => 'datetime',
            'paused_at' => 'datetime',
            'pause_history' => 'array',
            'reminders_sent' => 'array',
            'cycle' => 'integer',
            'target_value' => 'integer',
            'paused_seconds_total' => 'integer',
        ];
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function policy()
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('state', ['running', 'at_risk']);
    }

    public function scopeBreached(Builder $query): Builder
    {
        return $query->where('state', 'breached');
    }

    public function isFinished(): bool
    {
        return in_array($this->state, ['met', 'met_late', 'cancelled'], true);
    }

    /** Percentage of the allowed window consumed, ignoring paused time. */
    public function elapsedPercent(): float
    {
        if (! $this->started_at || ! $this->due_at) {
            return 0.0;
        }

        $total = $this->due_at->getTimestamp() - $this->started_at->getTimestamp();

        if ($total <= 0) {
            return 100.0;
        }

        $reference = $this->completed_at ?? ($this->paused_at ?? now());
        $elapsed = $reference->getTimestamp() - $this->started_at->getTimestamp() - $this->paused_seconds_total;

        return max(0.0, min(999.0, round($elapsed / $total * 100, 1)));
    }
}
