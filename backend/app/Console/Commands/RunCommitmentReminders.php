<?php

namespace App\Console\Commands;

use App\Domain\Engagement\CommitmentService;
use Illuminate\Console\Command;

class RunCommitmentReminders extends Command
{
    protected $signature = 'sasa:commitment-reminders {--project=}';

    protected $description = 'Flip due commitments to overdue and send the reminders configured for each project.';

    public function handle(CommitmentService $commitments): int
    {
        $result = $commitments->runReminderSweep(
            $this->option('project') ? (int) $this->option('project') : null
        );

        $this->info("Marked {$result['overdue']} overdue. Sent {$result['reminders']} reminders.");

        return self::SUCCESS;
    }
}
