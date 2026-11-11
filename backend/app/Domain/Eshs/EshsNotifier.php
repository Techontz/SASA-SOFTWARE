<?php

namespace App\Domain\Eshs;

use App\Models\EshsAnomaly;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\SasaNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * In-app notifications for the assurance workflow. Recipients follow the
 * workflow itself — the contractor's people when an anomaly is raised or
 * returned, the supervision team when evidence is submitted — so they are
 * resolved from permissions and the contractor on each membership rather than
 * from a role list.
 */
final class EshsNotifier
{
    public const RAISED = 'eshs.raised';

    public const EVIDENCE_SUBMITTED = 'eshs.evidence_submitted';

    public const RETURNED = 'eshs.returned';

    public const CLOSED = 'eshs.closed';

    public function raised(EshsAnomaly $anomaly): void
    {
        $this->send(
            self::RAISED,
            $anomaly,
            $this->contractorPeople($anomaly),
            "{$anomaly->reference}: a new ESHS anomaly for {$anomaly->contractor}",
            sprintf('%s risk · %s. %s', ucfirst($anomaly->risk_rating), $anomaly->subcategory, mb_substr($anomaly->description, 0, 200)),
            in_array($anomaly->risk_rating, ['high', 'critical'], true) ? 'warning' : 'info',
        );
    }

    public function evidenceSubmitted(EshsAnomaly $anomaly): void
    {
        $this->send(
            self::EVIDENCE_SUBMITTED,
            $anomaly,
            $this->holders($anomaly, 'eshs.verify'),
            "{$anomaly->reference}: closure evidence is ready to verify",
            "{$anomaly->contractor} has submitted closure evidence for verification.",
        );
    }

    public function returned(EshsAnomaly $anomaly): void
    {
        $this->send(
            self::RETURNED,
            $anomaly,
            $this->contractorPeople($anomaly)->merge($this->raiser($anomaly)),
            "{$anomaly->reference}: closure evidence returned",
            (string) $anomaly->return_reason,
            'warning',
        );
    }

    public function closed(EshsAnomaly $anomaly): void
    {
        $this->send(
            self::CLOSED,
            $anomaly,
            $this->contractorPeople($anomaly)->merge($this->raiser($anomaly)),
            "{$anomaly->reference}: verified and closed",
            "The anomaly for {$anomaly->contractor} has been verified and closed.",
            'success',
        );
    }

    /** People holding eshs.respond whose membership names this contractor. */
    private function contractorPeople(EshsAnomaly $anomaly)
    {
        return $this->holders($anomaly, 'eshs.respond', $anomaly->contractor);
    }

    private function holders(EshsAnomaly $anomaly, string $permission, ?string $contractor = null)
    {
        $userIds = ProjectMembership::query()
            ->where('project_id', $anomaly->project_id)
            ->where('status', 'active')
            ->when($contractor !== null, fn ($q) => $q->where('contractor_name', $contractor))
            ->whereHas('role.permissions', fn ($q) => $q->where('key', $permission))
            ->pluck('user_id');

        return User::query()->whereIn('id', $userIds)->get();
    }

    private function raiser(EshsAnomaly $anomaly)
    {
        return User::query()->whereKey($anomaly->raised_by)->get();
    }

    private function send(string $event, EshsAnomaly $anomaly, $users, string $subject, string $body, string $severity = 'info'): void
    {
        $actorId = auth()->id();

        $recipients = collect($users)
            ->unique('id')
            ->filter(fn (User $user) => $user->id !== $actorId
                && $user->status === 'active'
                && $user->archived_at === null
                && ! (($user->notification_preferences['muted'] ?? false) === true)
                && ! in_array($event, $user->notification_preferences['muted_events'] ?? [], true))
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new SasaNotification(
                eventKey: $event,
                subject: $subject,
                body: $body,
                payload: [
                    'reference' => $anomaly->reference,
                    'project_id' => $anomaly->project_id,
                    'url' => "/eshs/{$anomaly->id}",
                ],
                channels: ['in_app'],
                severity: $severity,
            ));
        } catch (\Throwable $e) {
            // A failed notification must never undo the workflow step.
            Log::error('eshs.notification_failed', ['event' => $event, 'anomaly' => $anomaly->id, 'error' => $e->getMessage()]);
        }
    }
}
