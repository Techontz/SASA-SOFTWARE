<?php

namespace App\Console\Commands;

use App\Domain\Billing\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The monthly billing of agreed plans, run daily. On an agreement's due date
 * it raises the invoice by itself and, when the organisation turned auto-pay
 * on, sends a mobile money prompt to the payer's phone. The payer still
 * approves it with their PIN: nothing is debited silently. Open invoices past
 * the grace period become overdue and their agreement past_due; nothing is
 * ever suspended automatically. Safe to run more than once a day.
 */
class RunBillingCycle extends Command
{
    protected $signature = 'billing:run-cycle {--date= : Run as if today were this date (YYYY-MM-DD)}';

    protected $description = 'Issue due invoices for agreed plans, send auto-pay prompts, and mark unpaid invoices overdue.';

    public function handle(InvoiceService $invoices): int
    {
        $today = $this->option('date') ? Carbon::parse((string) $this->option('date'))->startOfDay() : null;

        $result = $invoices->runCycle($today);

        $this->info("Issued {$result['issued']} invoices. Sent {$result['auto_pay_started']} auto-pay prompts. Marked {$result['overdue']} overdue.");

        return self::SUCCESS;
    }
}
