<?php

namespace App\Policies;

use App\Domain\Grievance\GrievanceVisibility;
use App\Models\Grievance;
use App\Models\User;
use App\Policies\Concerns\ScopesToTenant;
use Illuminate\Auth\Access\Response;

/**
 * A restricted case is invisible outside its handling group: `view` returns
 * false, which the API renders as 404 rather than 403, so the existence of the
 * case is not leaked by the difference.
 */
class GrievancePolicy
{
    use ScopesToTenant;

    public function __construct(private readonly GrievanceVisibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'grievance.view');
    }

    /**
     * A restricted case is not "forbidden" to an outsider — it is INVISIBLE.
     * Denying as not-found keeps the existence of a SEA/SH or ethics case from
     * leaking through the difference between 403 and 404.
     */
    public function view(User $user, Grievance $grievance): Response
    {
        if (! $this->allows($user, 'grievance.view', $grievance)) {
            return Response::deny('You do not have permission to see grievance cases on this project.');
        }

        return $this->visibility->canSeeCase($grievance)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /** Boolean form, for the action checks below. */
    private function canSee(User $user, Grievance $grievance): bool
    {
        return $this->allows($user, 'grievance.view', $grievance)
            && $this->visibility->canSeeCase($grievance);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'grievance.create');
    }

    public function update(User $user, Grievance $grievance): bool
    {
        return $this->canSee($user, $grievance)
            && $this->isEditable($grievance)
            && $this->context()->can('grievance.update');
    }

    public function classify(User $user, Grievance $grievance): bool
    {
        return $this->canSee($user, $grievance) && $this->context()->can('grievance.classify');
    }

    public function assign(User $user, Grievance $grievance): bool
    {
        return $this->canSee($user, $grievance) && $this->context()->can('grievance.assign');
    }

    public function acknowledge(User $user, Grievance $grievance): bool
    {
        return $this->canSee($user, $grievance) && $this->context()->can('grievance.acknowledge');
    }

    public function investigate(User $user, Grievance $grievance): bool
    {
        return $this->canSee($user, $grievance) && $this->context()->can('grievance.investigate');
    }

    public function resolve(User $user, Grievance $grievance): bool
    {
        return $this->canSee($user, $grievance) && $this->context()->can('grievance.resolve');
    }

    public function close(User $user, Grievance $grievance): bool
    {
        return $this->canSee($user, $grievance) && $this->context()->can('grievance.close');
    }

    public function reopen(User $user, Grievance $grievance): bool
    {
        return $this->canSee($user, $grievance) && $this->context()->can('grievance.reopen');
    }

    public function escalate(User $user, Grievance $grievance): bool
    {
        return $this->canSee($user, $grievance) && $this->context()->can('grievance.escalate');
    }

    /** Identity release is a separate decision from case access. */
    public function viewIdentity(User $user, Grievance $grievance): bool
    {
        return $this->canSee($user, $grievance) && $this->visibility->canSeeIdentity($grievance);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, 'grievance.export');
    }
}
