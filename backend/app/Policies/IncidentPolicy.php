<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;
use App\Policies\Concerns\ScopesToTenant;

class IncidentPolicy
{
    use ScopesToTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'incident.view');
    }

    public function view(User $user, Incident $incident): bool
    {
        return $this->allows($user, 'incident.view', $incident);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'incident.report');
    }

    /**
     * Editing (and adding evidence files, which the attachment endpoint
     * authorises as `update`): a manager at any point before archive, or the
     * person who reported it while it is still only submitted.
     */
    public function update(User $user, Incident $incident): bool
    {
        if (! $this->isEditable($incident)) {
            $this->allows($user, 'incident.view', $incident);

            return false;
        }

        if ($this->allows($user, 'incident.manage', $incident)) {
            return true;
        }

        return $incident->status === 'submitted'
            && (int) $incident->reported_by === (int) $user->id
            && $this->allows($user, 'incident.report', $incident);
    }

    /** Reviewing, closing and archiving. */
    public function manage(User $user, Incident $incident): bool
    {
        return $this->isEditable($incident) && $this->allows($user, 'incident.manage', $incident);
    }
}
