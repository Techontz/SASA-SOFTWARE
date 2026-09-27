<?php

namespace App\Console\Commands;

use App\Domain\Notification\NotificationDispatcher;
use App\Domain\Notification\NotificationEvents;
use App\Models\EngagementPlan;
use App\Models\Stakeholder;
use Illuminate\Console\Command;

class NotifyStakeholderReviews extends Command
{
    protected $signature = 'sasa:daily-reminders';

    protected $description = 'Remind owners about register entries due for review and engagements coming up.';

    public function handle(NotificationDispatcher $notifications): int
    {
        $reviews = 0;

        Stakeholder::query()->acrossTenants()
            ->with('project')
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereDate('review_date', today())
            ->chunkById(200, function ($stakeholders) use ($notifications, &$reviews) {
                foreach ($stakeholders as $stakeholder) {
                    if (! $stakeholder->project) {
                        continue;
                    }

                    $notifications->dispatch(
                        eventKey: NotificationEvents::STAKEHOLDER_REVIEW_DUE,
                        project: $stakeholder->project,
                        payload: [
                            'reference' => $stakeholder->reference,
                            'name' => $stakeholder->name,
                            'review_date' => $stakeholder->review_date?->toFormattedDateString(),
                            'url' => "/stakeholders/{$stakeholder->id}",
                        ],
                        extraUserIds: array_filter([$stakeholder->owner_id]),
                    );

                    $reviews++;
                }
            });

        $upcoming = 0;

        EngagementPlan::query()->acrossTenants()
            ->with('project')
            ->where('status', 'planned')
            ->whereNull('archived_at')
            ->whereDate('target_date', today()->addDays(3))
            ->chunkById(200, function ($plans) use ($notifications, &$upcoming) {
                foreach ($plans as $plan) {
                    if (! $plan->project) {
                        continue;
                    }

                    $notifications->dispatch(
                        eventKey: NotificationEvents::ENGAGEMENT_PLAN_DUE,
                        project: $plan->project,
                        payload: [
                            'reference' => $plan->reference,
                            'title' => $plan->title,
                            'target_date' => $plan->target_date?->toFormattedDateString(),
                            'location' => $plan->location_text ?? '—',
                            'url' => "/engagements/plans/{$plan->id}",
                        ],
                        extraUserIds: array_filter([$plan->owner_id]),
                    );

                    $upcoming++;
                }
            });

        $this->info("{$reviews} review reminders and {$upcoming} engagement reminders sent.");

        return self::SUCCESS;
    }
}
