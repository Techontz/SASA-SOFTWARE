<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** "My open Level 4–5". "Overdue commitments — Northern district". */
class SavedView extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'user_id', 'entity', 'name', 'filters',
        'columns', 'sort', 'visibility', 'shared_with_role_id', 'is_pinned',
    ];

    protected function casts(): array
    {
        return ['filters' => 'array', 'columns' => 'array', 'is_pinned' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'shared_with_role_id');
    }

    public function scopeVisibleTo(Builder $query, User $user, ?int $roleId): Builder
    {
        return $query->where(function (Builder $q) use ($user, $roleId) {
            $q->where('user_id', $user->id)
                ->orWhere('visibility', 'project')
                ->orWhere(fn (Builder $r) => $r->where('visibility', 'role')->where('shared_with_role_id', $roleId));
        });
    }
}
