<?php

namespace App\Console\Commands;

use App\Domain\Engagement\EngagementService;
use Illuminate\Console\Command;

class MarkMissedEngagements extends Command
{
    protected $signature = 'sasa:missed-engagements {--project=}';

    protected $description = 'Flag planned engagements whose window has passed with nothing logged against them.';

    public function handle(EngagementService $engagements): int
    {
        $count = $engagements->markMissedPlans(
            $this->option('project') ? (int) $this->option('project') : null
        );

        $this->info("{$count} planned engagements marked as missed.");

        return self::SUCCESS;
    }
}
