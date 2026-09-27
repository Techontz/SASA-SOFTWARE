<?php

namespace App\Domain\Notification;

use App\Models\NotificationRule;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\SasaNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Resolves a configurable rule into a recipient list and delivers it.
 *
 * Channels today: in-app and e-mail. SMS and WhatsApp are additive — the rule
 * already carries the channel list, so enabling them is configuration plus a
 * driver, not a redesign.
 */
final class NotificationDispatcher
{
    /**
     * @param  array<string,mixed>  $payload  Values substituted into the template.
     * @param  array<int,int>  $extraUserIds  Owner/assignee style direct recipients.
     */
    public function dispatch(
        string $eventKey,
        Project $project,
        array $payload = [],
        array $extraUserIds = [],
        string $severity = 'info',
    ): int {
        $rule = $this->ruleFor($eventKey, $project);

        if (! $rule || ! $rule->is_active) {
            return 0;
        }

        if (! $this->conditionsMet($rule, $payload)) {
            return 0;
        }

        $recipients = $this->recipients($rule, $project, $extraUserIds);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $subject = $this->render($rule->template_subject ?? $rule->name, $payload);
        $body = $this->render($rule->template_body ?? '', $payload);

        try {
            NotificationFacade::send($recipients, new SasaNotification(
                eventKey: $eventKey,
                subject: $subject,
                body: $body,
                payload: $payload,
                channels: $rule->channels ?? ['in_app'],
                severity: $severity,
            ));
        } catch (\Throwable $e) {
            Log::error('notification.dispatch_failed', [
                'event' => $eventKey,
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        return $recipients->count();
    }

    public function ruleFor(string $eventKey, Project $project): ?NotificationRule
    {
        return NotificationRule::query()
            ->where('organisation_id', $project->organisation_id)
            ->where('event_key', $eventKey)
            ->where(fn ($q) => $q->where('project_id', $project->id)->orWhereNull('project_id'))
            ->orderByRaw('project_id IS NULL')
            ->first();
    }

    /** @return Collection<int,User> */
    private function recipients(NotificationRule $rule, Project $project, array $extraUserIds): Collection
    {
        $userIds = [];

        if ($rule->recipient_roles) {
            $userIds = ProjectMembership::query()
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->whereHas('role', fn ($q) => $q->whereIn('key', $rule->recipient_roles))
                ->pluck('user_id')
                ->all();
        }

        if ($rule->notify_owner || $rule->notify_assignee) {
            $userIds = array_merge($userIds, $extraUserIds);
        }

        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->get()
            ->filter(fn (User $user) => $this->userWantsEvent($user, $rule->event_key))
            ->values();
    }

    /** Users control their own digest preferences. */
    private function userWantsEvent(User $user, string $eventKey): bool
    {
        $preferences = $user->notification_preferences ?? [];

        if (($preferences['muted'] ?? false) === true) {
            return false;
        }

        return ! in_array($eventKey, $preferences['muted_events'] ?? [], true);
    }

    private function conditionsMet(NotificationRule $rule, array $payload): bool
    {
        foreach ($rule->conditions ?? [] as $condition => $expected) {
            $met = match (true) {
                str_ends_with($condition, '_gte') => ($payload[substr($condition, 0, -4)] ?? 0) >= $expected,
                str_ends_with($condition, '_lte') => ($payload[substr($condition, 0, -4)] ?? 0) <= $expected,
                str_ends_with($condition, '_in') => in_array($payload[substr($condition, 0, -3)] ?? null, (array) $expected, true),
                default => ($payload[$condition] ?? null) == $expected,
            };

            if (! $met) {
                return false;
            }
        }

        return true;
    }

    public function render(string $template, array $payload): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function (array $matches) use ($payload) {
            $value = $payload[$matches[1]] ?? '';

            return is_scalar($value) ? (string) $value : $matches[0];
        }, $template);
    }
}
