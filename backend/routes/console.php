<?php

use App\Console\Commands\BackupDatabase;
use App\Console\Commands\MarkMissedEngagements;
use App\Console\Commands\NotifyStakeholderReviews;
use App\Console\Commands\RunCommitmentReminders;
use App\Console\Commands\RunSlaSweep;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| SASA scheduled work
|--------------------------------------------------------------------------
|
| Run `php artisan schedule:work` in development, or add the standard
| Laravel cron entry in production:
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
|
*/

// The SLA engine's heartbeat. Frequent enough that "approaching breach"
// reminders arrive while there is still time to act on them.
Schedule::command(RunSlaSweep::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(RunCommitmentReminders::class)
    ->dailyAt('07:00')
    ->withoutOverlapping();

Schedule::command(MarkMissedEngagements::class)
    ->dailyAt('01:30')
    ->withoutOverlapping();

Schedule::command(NotifyStakeholderReviews::class)
    ->dailyAt('07:15')
    ->withoutOverlapping();

Schedule::command(BackupDatabase::class)
    ->dailyAt('02:00')
    ->onOneServer();

Schedule::command('queue:prune-failed --hours=336')->weekly();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
