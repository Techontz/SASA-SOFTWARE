<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLogger;
use App\Models\Invoice;
use App\Models\OrganisationAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Monthly invoices for agreed plans, and the billing cycle that raises them.
 *
 * WHAT "AUTOMATIC" MEANS HERE. Snippe's mobile money is a USSD push: the
 * payer gets a prompt on their phone and approves it with their PIN. There is
 * no silent debit and this code never claims one. On the due date the cycle
 * raises the invoice by itself and, when the organisation has turned auto-pay
 * on with a mobile money number, immediately sends the payment prompt to that
 * phone. If nobody approves it, the invoice simply stays open.
 *
 * Invoices are never deleted: a mistaken one is voided, with a reason, and
 * every change is audited (invoice.created, invoice.status_changed, plus
 * invoice.marked_paid / invoice.voided carrying the note or reason).
 */
final class InvoiceService
{
    /** At most this many missed periods are caught up for one agreement in one run. */
    private const MAX_CATCH_UP = 24;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly InvoiceSettlement $settlement,
        private readonly BillingNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The daily cycle. Idempotent: running it twice on the same day issues
     * nothing new, because next_due_on has already moved on and an invoice
     * period is unique per agreement.
     *
     * @return array{issued:int,auto_pay_started:int,overdue:int}
     */
    public function runCycle(?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $issued = 0;
        $autoPay = 0;

        $due = OrganisationAgreement::query()
            ->whereNull('archived_at')
            ->whereIn('status', OrganisationAgreement::BILLABLE_STATUSES)
            ->whereDate('next_due_on', '<=', $today)
            ->pluck('id');

        foreach ($due as $agreementId) {
            $last = null;

            for ($i = 0; $i < self::MAX_CATCH_UP; $i++) {
                $invoice = $this->issueFor((int) $agreementId, $today);

                if (! $invoice) {
                    break;
                }

                $issued++;
                $last = $invoice;
            }

            // Several missed periods are invoiced together, but only the most
            // recent gets a phone prompt: a burst of USSD pushes helps nobody.
            if ($last) {
                $autoPay += $this->afterIssue($last) ? 1 : 0;
            }
        }

        return ['issued' => $issued, 'auto_pay_started' => $autoPay, 'overdue' => $this->markOverdue($today)];
    }

    /**
     * An administrator issues the next invoice now, without waiting for the
     * due date: the period starting at next_due_on. Same notification and
     * auto-pay prompt as the cycle.
     */
    public function issueNow(OrganisationAgreement $agreement): Invoice
    {
        if (! in_array($agreement->status, OrganisationAgreement::BILLABLE_STATUSES, true) || $agreement->archived_at !== null) {
            throw new DomainRuleException("An invoice can only be issued for an active or past due agreement. This one is {$agreement->status}.", 'agreement_not_billable');
        }

        $invoice = $this->issueFor($agreement->id, null)
            ?? throw new DomainRuleException('The invoice for this period has already been issued.', 'already_issued');

        $this->afterIssue($invoice);

        return $invoice->fresh(['organisation:id,name', 'agreement:id,public_id,name', 'latestPayment']);
    }

    /**
     * Mark open invoices overdue once their due date is more than the grace
     * period ago, and the agreement past_due. Never suspends: that is a
     * decision for a person.
     */
    public function markOverdue(?Carbon $today = null): int
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $cutoff = $today->copy()->subDays(max(0, (int) config('sasa.billing.grace_days', 7)));

        $ids = Invoice::query()
            ->where('status', 'open')
            ->whereDate('due_on', '<', $cutoff)
            ->pluck('id');

        $marked = 0;

        foreach ($ids as $id) {
            $invoice = DB::transaction(function () use ($id) {
                $invoice = Invoice::query()->lockForUpdate()->find($id);

                if (! $invoice || $invoice->status !== 'open') {
                    return null;
                }

                $invoice->forceFill(['status' => 'overdue'])->save();

                $agreement = OrganisationAgreement::query()->lockForUpdate()->find($invoice->organisation_agreement_id);

                if ($agreement && $agreement->status === 'active') {
                    $agreement->forceFill(['status' => 'past_due'])->save();
                }

                return $invoice;
            });

            if ($invoice) {
                $marked++;
                $this->notifier->invoiceOverdue($invoice);
            }
        }

        return $marked;
    }

    /** Money received outside SASA (a bank transfer), recorded by an administrator. */
    public function markPaid(Invoice $invoice, User $admin, ?string $note): Invoice
    {
        return DB::transaction(function () use ($invoice, $admin, $note) {
            $invoice = $this->lockPayable($invoice->id);

            $this->closePendingPayments($invoice, 'The invoice was marked paid by hand.');
            $this->settlement->markPaid($invoice);

            $this->audit->record(
                action: 'invoice.marked_paid',
                entity: $invoice,
                summary: 'Marked paid outside SASA by '.$admin->name.'.',
                context: array_filter(['note' => self::nullableText($note)]),
            );

            return $invoice->fresh(['organisation:id,name', 'agreement:id,public_id,name', 'latestPayment']);
        });
    }

    /** Cancel an invoice that should not be paid. It is kept, with the reason. */
    public function void(Invoice $invoice, User $admin, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $admin, $reason) {
            $invoice = $this->lockPayable($invoice->id);

            $this->closePendingPayments($invoice, 'The invoice was voided.');
            $invoice->forceFill(['status' => 'void'])->save();

            $this->audit->record(
                action: 'invoice.voided',
                entity: $invoice,
                summary: 'Voided by '.$admin->name.'.',
                context: ['reason' => trim($reason)],
            );

            $this->settlement->restoreIfSettled($invoice->organisation_agreement_id);

            return $invoice->fresh(['organisation:id,name', 'agreement:id,public_id,name', 'latestPayment']);
        });
    }

    /** The customer pays one of its own organisation's invoices. */
    public function pay(int $organisationId, string $publicId, string $method, ?string $phone, User $payer): Payment
    {
        return $this->payments->startForInvoice($this->findForOrganisation($organisationId, $publicId), $method, $phone, $payer);
    }

    /**
     * An invoice of THIS organisation, or not found. Another organisation's
     * invoice is indistinguishable from one that does not exist.
     */
    public function findForOrganisation(int $organisationId, string $publicId): Invoice
    {
        return Invoice::query()
            ->where('organisation_id', $organisationId)
            ->where('public_id', $publicId)
            ->firstOr(fn () => throw (new ModelNotFoundException)->setModel(Invoice::class, [$publicId]));
    }

    /** @return Collection<int,Invoice> newest first */
    public function forOrganisation(int $organisationId): Collection
    {
        return Invoice::query()
            ->where('organisation_id', $organisationId)
            ->with('latestPayment')
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(240)
            ->get();
    }

    /**
     * The period after next_due_on ends the day before the following due
     * date: one month later, on the agreement's billing day. (So a first
     * period that starts on a day other than the billing day is shorter or
     * longer than a month; it is billed at the monthly amount.)
     */
    public static function followingDueDate(Carbon $dueOn, int $billingDay): Carbon
    {
        return $dueOn->copy()->startOfMonth()->addMonthNoOverflow()->day(max(1, min(28, $billingDay)));
    }

    /**
     * Issue the invoice for the period starting at the agreement's
     * next_due_on, and move next_due_on on. With $dueBy, only when
     * next_due_on <= $dueBy (the cycle); without it, whatever the date (an
     * administrator issuing now). Null when there is nothing to issue.
     */
    private function issueFor(int $agreementId, ?Carbon $dueBy): ?Invoice
    {
        return DB::transaction(function () use ($agreementId, $dueBy) {
            $agreement = OrganisationAgreement::query()->lockForUpdate()->find($agreementId);

            if (! $agreement || $agreement->archived_at !== null
                || ! in_array($agreement->status, OrganisationAgreement::BILLABLE_STATUSES, true)) {
                return null;
            }

            $periodStart = $agreement->next_due_on->copy()->startOfDay();

            if ($dueBy !== null && $periodStart->gt($dueBy)) {
                return null;
            }

            $following = self::followingDueDate($periodStart, $agreement->billing_day);

            $exists = Invoice::query()
                ->where('organisation_agreement_id', $agreement->id)
                ->whereDate('period_start', $periodStart)
                ->exists();

            $invoice = null;

            if (! $exists) {
                $invoice = new Invoice;
                $invoice->fill([
                    'organisation_id' => $agreement->organisation_id,
                    'organisation_agreement_id' => $agreement->id,
                    'period_start' => $periodStart,
                    'period_end' => $following->copy()->subDay(),
                    'amount' => $agreement->monthly_amount,
                    'currency' => $agreement->currency,
                    'due_on' => $periodStart,
                ]);
                $invoice->public_id = (string) Str::ulid();
                $invoice->number = $this->nextNumber();
                $invoice->status = 'open';
                $invoice->save();
            }

            // Moving on even when the invoice already existed is what keeps
            // a second run from ever issuing the same period twice.
            $agreement->forceFill(['next_due_on' => $following])->save();

            return $invoice;
        });
    }

    /** Send the auto-pay prompt (if on) and tell the billing users. True when a prompt was sent. */
    private function afterIssue(Invoice $invoice): bool
    {
        $agreement = $invoice->agreement()->first();
        $started = false;
        $phone = null;

        if ($agreement && $agreement->auto_pay && $agreement->auto_pay_phone) {
            $phone = $agreement->auto_pay_phone;

            try {
                // A prompt on the payer's phone, approved with their PIN. Not a debit.
                $payment = $this->payments->startForInvoice($invoice, 'mobile_money', $phone, null);
                $started = $payment->status !== 'failed';
            } catch (\Throwable $e) {
                Log::warning('billing.auto_pay_failed', ['invoice' => $invoice->number, 'error' => $e->getMessage()]);
            }

            if (! $started) {
                $this->audit->record(
                    action: 'invoice.auto_pay_failed',
                    entity: $invoice,
                    summary: 'The auto-pay prompt could not be sent. The invoice stays open to be paid by hand.',
                );
            }
        }

        $this->notifier->invoiceIssued($invoice->fresh(), $started, $phone);

        return $started;
    }

    private function lockPayable(int $invoiceId): Invoice
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

        if ($invoice->status === 'paid') {
            throw new DomainRuleException("{$invoice->number} is already paid.", 'already_paid');
        }

        if ($invoice->status === 'void') {
            throw new DomainRuleException("{$invoice->number} was voided.", 'invoice_void');
        }

        return $invoice;
    }

    private function closePendingPayments(Invoice $invoice, string $reason): void
    {
        $invoice->payments()->where('status', 'pending')->get()->each(function (Payment $payment) use ($reason) {
            $payment->forceFill(['status' => 'cancelled', 'failure_reason' => $reason])->save();
        });
    }

    /** INV-2026-0001, numbered per calendar year, under a row lock. */
    private function nextNumber(): string
    {
        $config = (array) config('sasa.billing.invoice_reference', []);
        $prefix = ($config['prefix'] ?? 'INV').'-'.now()->format('Y').'-';
        $pad = (int) ($config['pad'] ?? 4);

        $last = Invoice::query()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('number');

        $next = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
