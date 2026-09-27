<?php

namespace App\Policies;

use App\Models\EngagementPlan;
use App\Models\User;
use App\Policies\Concerns\ScopesToTenant;

class EngagementPlanPolicy
{
    use ScopesToTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'engagement.view');
    }

    public function view(User $user, EngagementPlan $plan): bool
    {
        return $this->allows($user, 'engagement.view', $plan);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'engagement.plan');
    }

    public function update(User $user, EngagementPlan $plan): bool
    {
        return $this->isEditable($plan) && $this->allows($user, 'engagement.plan', $plan);
    }

    public function archive(User $user, EngagementPlan $plan): bool
    {
        return $this->allows($user, 'engagement.archive', $plan);
    }
}
