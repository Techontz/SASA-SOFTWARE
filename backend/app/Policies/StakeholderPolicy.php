<?php

namespace App\Policies;

use App\Models\Stakeholder;
use App\Models\User;
use App\Policies\Concerns\ScopesToTenant;

class StakeholderPolicy
{
    use ScopesToTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'stakeholder.view');
    }

    public function view(User $user, Stakeholder $stakeholder): bool
    {
        return $this->allows($user, 'stakeholder.view', $stakeholder);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'stakeholder.create');
    }

    public function update(User $user, Stakeholder $stakeholder): bool
    {
        return $this->isEditable($stakeholder) && $this->allows($user, 'stakeholder.update', $stakeholder);
    }

    public function archive(User $user, Stakeholder $stakeholder): bool
    {
        return $this->allows($user, 'stakeholder.archive', $stakeholder);
    }

    public function overridePriority(User $user, Stakeholder $stakeholder): bool
    {
        return $this->isEditable($stakeholder) && $this->allows($user, 'stakeholder.override_priority', $stakeholder);
    }

    public function merge(User $user, Stakeholder $stakeholder): bool
    {
        return $this->allows($user, 'stakeholder.merge', $stakeholder);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, 'stakeholder.export');
    }
}
