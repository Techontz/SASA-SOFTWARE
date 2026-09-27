<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateSlaClocks;
use Illuminate\Console\Command;

class RunSlaSweep extends Command
{
    protected $signature = 'sasa:sla-sweep {--project= : Limit to one project} {--sync : Run now instead of queueing}';

    protected $description = 'Re-evaluate every open SLA clock, send reminders and escalate breaches.';

    public function handle(): int
    {
        $projectId = $this->option('project') ? (int) $this->option('project') : null;

        if ($this->option('sync')) {
            dispatch_sync(new EvaluateSlaClocks($projectId));
            $this->info('SLA sweep complete.');
        } else {
            EvaluateSlaClocks::dispatch($projectId);
            $this->info('SLA sweep queued.');
        }

        return self::SUCCESS;
    }
}
