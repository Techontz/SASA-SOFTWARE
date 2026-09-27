<?php

namespace App\Policies;

use App\Models\Commitment;
use App\Models\User;
use App\Policies\Concerns\ScopesToTenant;

class CommitmentPolicy
{
    use ScopesToTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'commitment.view');
    }

    public function view(User $user, Commitment $commitment): bool
    {
        return $this->allows($user, 'commitment.view', $commitment);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'commitment.manage');
    }

    public function update(User $user, Commitment $commitment): bool
    {
        return $this->isEditable($commitment) && $this->allows($user, 'commitment.manage', $commitment);
    }

    /** Verification is deliberately a different permission from completion. */
    public function verify(User $user, Commitment $commitment): bool
    {
        return $this->allows($user, 'commitment.verify', $commitment);
    }
}
