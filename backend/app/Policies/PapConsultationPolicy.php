<?php

namespace App\Policies;

use App\Models\Pap\PapConsultation;
use App\Models\User;
use App\Policies\Concerns\ScopesToTenant;

class PapConsultationPolicy
{
    use ScopesToTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'pap.view');
    }

    public function view(User $user, PapConsultation $consultation): bool
    {
        return $this->allows($user, 'pap.view', $consultation);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'pap.manage');
    }

    public function update(User $user, PapConsultation $consultation): bool
    {
        return $this->isEditable($consultation) && $this->allows($user, 'pap.manage', $consultation);
    }
}
