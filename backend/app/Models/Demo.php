<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A demo workspace: the organisation and project given to a company that
 * asked for a demo, and the person invited into it, for a limited time.
 * Platform level, addressed in URLs by its ULID.
 *
 * active    — the workspace works until ends_at.
 * expired   — ends_at passed (set by demos:expire, and treated as such the
 *             moment ends_at passes even before the command runs).
 * ended     — an administrator ended it early.
 * converted — the company signed an agreement; the workspace is theirs.
 *
 * Every change is audited by the Auditable trait (demo.created,
 * demo.updated, demo.status_changed).
 */
class Demo extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'demo';

    public const STATUSES = ['active', 'expired', 'ended', 'converted'];

    /** Statuses in which the workspace refuses every request. */
    public const BLOCKED_STATUSES = ['expired', 'ended'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function auditReference(): ?string
    {
        return $this->public_id;
    }

    public function enquiry()
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * The status the workspace is actually in now: an active demo whose end
     * has passed is expired, whether or not demos:expire has run yet.
     */
    public function effectiveStatus(): string
    {
        if ($this->status === 'active' && $this->ends_at !== null && $this->ends_at->isPast()) {
            return 'expired';
        }

        return $this->status;
    }

    public function isBlocked(): bool
    {
        return in_array($this->effectiveStatus(), self::BLOCKED_STATUSES, true);
    }
}
