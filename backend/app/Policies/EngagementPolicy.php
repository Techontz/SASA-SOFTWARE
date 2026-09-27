<?php

namespace App\Policies;

use App\Models\Engagement;
use App\Models\User;
use App\Policies\Concerns\ScopesToTenant;

class EngagementPolicy
{
    use ScopesToTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'engagement.view');
    }

    public function view(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'engagement.view', $engagement);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'engagement.log');
    }

    public function update(User $user, Engagement $engagement): bool
    {
        return $this->isEditable($engagement) && $this->allows($user, 'engagement.log', $engagement);
    }

    public function archive(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'engagement.archive', $engagement);
    }
}
