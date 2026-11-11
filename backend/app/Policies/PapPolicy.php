<?php

namespace App\Policies;

use App\Models\Pap;
use App\Models\User;
use App\Policies\Concerns\ScopesToTenant;

class PapPolicy
{
    use ScopesToTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'pap.view');
    }

    public function view(User $user, Pap $pap): bool
    {
        return $this->allows($user, 'pap.view', $pap);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'pap.manage');
    }

    /** Profile, survey, inventories, valuation, entitlements, relocation, livelihood, documents. */
    public function update(User $user, Pap $pap): bool
    {
        return $this->isEditable($pap) && $this->allows($user, 'pap.manage', $pap);
    }

    /** Recording or approving money is deliberately a separate permission. */
    public function managePayments(User $user, Pap $pap): bool
    {
        return $this->isEditable($pap) && $this->allows($user, 'pap.manage_payments', $pap);
    }

    public function archive(User $user, Pap $pap): bool
    {
        return $this->isEditable($pap) && $this->allows($user, 'pap.manage', $pap);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, 'pap.export');
    }
}
