<?php

namespace App\Policies;

use App\Models\Concern;
use App\Models\User;
use App\Policies\Concerns\ScopesToTenant;

class ConcernPolicy
{
    use ScopesToTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'concern.view');
    }

    public function view(User $user, Concern $concern): bool
    {
        return $this->allows($user, 'concern.view', $concern);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'concern.manage');
    }

    public function update(User $user, Concern $concern): bool
    {
        return $this->isEditable($concern) && $this->allows($user, 'concern.manage', $concern);
    }

    public function escalate(User $user, Concern $concern): bool
    {
        return $this->allows($user, 'concern.escalate', $concern)
            && $this->context()->can('grievance.create');
    }
}
