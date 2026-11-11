<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\EshsAnomaly;
use App\Models\User;
use App\Policies\Concerns\ScopesToTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The client's rules, enforced here rather than in the interface:
 *
 *  - only the client / supervision team (eshs.raise) raises anomalies;
 *  - the contractor (eshs.respond) updates progress and uploads closure
 *    evidence, and cannot close;
 *  - only the supervision team (eshs.verify) closes or returns;
 *  - nobody changes a closed anomaly;
 *  - a contractor's people see only their own company's anomalies — another
 *    contractor's anomaly is not found, not forbidden.
 */
class EshsAnomalyPolicy
{
    use ScopesToTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'eshs.view');
    }

    public function view(User $user, EshsAnomaly $anomaly): bool
    {
        return $this->allowsOn($user, 'eshs.view', $anomaly);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'eshs.raise');
    }

    /**
     * Also what an attachment upload authorises: the raiser adds evidence of
     * the anomaly, the contractor adds closure evidence. Closed is read-only.
     */
    public function update(User $user, EshsAnomaly $anomaly): bool
    {
        $this->guardScope($anomaly);

        return $this->isChangeable($anomaly)
            && ($this->allowsOn($user, 'eshs.raise', $anomaly) || $this->allowsOn($user, 'eshs.respond', $anomaly));
    }

    /** The raiser's own fields: what was found, where, how serious. */
    public function edit(User $user, EshsAnomaly $anomaly): bool
    {
        return $this->isChangeable($anomaly) && $this->allowsOn($user, 'eshs.raise', $anomaly);
    }

    /** Progress and submission for verification — the contractor's part. */
    public function respond(User $user, EshsAnomaly $anomaly): bool
    {
        return $this->isChangeable($anomaly) && $this->allowsOn($user, 'eshs.respond', $anomaly);
    }

    /** Close or return — the supervision team's part. */
    public function verify(User $user, EshsAnomaly $anomaly): bool
    {
        return $this->isChangeable($anomaly) && $this->allowsOn($user, 'eshs.verify', $anomaly);
    }

    /**
     * Removing a file is narrower than adding one: a contractor may take back
     * only what they uploaded; the raiser's evidence stays unless the
     * supervision team removes it.
     */
    public function removeAttachment(User $user, EshsAnomaly $anomaly, Attachment $attachment): bool
    {
        if ($attachment->uploaded_by === $user->id) {
            return $this->update($user, $anomaly);
        }

        return $this->isChangeable($anomaly)
            && ($this->allowsOn($user, 'eshs.raise', $anomaly) || $this->allowsOn($user, 'eshs.verify', $anomaly));
    }

    public function archive(User $user, EshsAnomaly $anomaly): bool
    {
        return $this->isEditable($anomaly) && $this->allowsOn($user, 'eshs.verify', $anomaly);
    }

    private function allowsOn(User $user, string $permission, EshsAnomaly $anomaly): bool
    {
        $allowed = $this->allows($user, $permission, $anomaly);
        $this->guardScope($anomaly);

        return $allowed;
    }

    /** Another contractor's anomaly does not exist for this viewer. */
    private function guardScope(EshsAnomaly $anomaly): void
    {
        $contractor = EshsAnomaly::contractorScopeFor($this->context()->membership(), $this->context()->roleKey());

        if ($contractor !== null && $anomaly->contractor !== $contractor) {
            throw new NotFoundHttpException;
        }
    }

    private function isChangeable(EshsAnomaly $anomaly): bool
    {
        return $this->isEditable($anomaly) && ! $anomaly->isClosed();
    }
}
