<?php

namespace App\Console\Commands;

use App\Domain\Demo\DemoService;
use Illuminate\Console\Command;

/**
 * Marks active demo workspaces whose end has passed as expired. The project
 * is already shut the moment ends_at passes (DemoAccess checks the date);
 * this makes the status say so for lists and reports. Safe to run any number
 * of times.
 */
class ExpireDemos extends Command
{
    protected $signature = 'demos:expire';

    protected $description = 'Mark demo workspaces past their end date as expired.';

    public function handle(DemoService $demos): int
    {
        $count = $demos->expireDue();

        $this->info("Marked {$count} demo".($count === 1 ? '' : 's').' expired.');

        return self::SUCCESS;
    }
}
