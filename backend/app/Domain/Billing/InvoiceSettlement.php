<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLogger;
use App\Models\Invoice;
use App\Models\OrganisationAgreement;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * An invoice is settled: by a confirmed payment, or by hand when the money
 * came in outside SASA (a bank transfer). When nothing is left overdue, an
 * agreement that was past_due is active again. A suspended agreement is never
 * reactivated here: lifting a suspension is a decision for a person.
 *
 * Kept apart from PaymentService and InvoiceService so both can use it.
 */
final class InvoiceSettlement
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** A payment for this invoice succeeded. Runs inside the payment's transaction. */
    public function paidByPayment(Payment $payment): void
    {
        $invoice = Invoice::query()->lockForUpdate()->find($payment->invoice_id);

        if (! $invoice) {
            return;
        }

        if (! $invoice->isPayable()) {
            // Money arrived for an invoice already paid or voided: say so, so
            // someone can refund or reconcile it.
            $this->audit->record(
                action: 'invoice.paid_after_close',
                entity: $invoice,
                summary: 'A payment succeeded for an invoice that was already '.$invoice->status.'.',
                context: ['payment' => $payment->public_id],
            );

            return;
        }

        $this->markPaid($invoice);
    }

    /** Mark a locked, payable invoice paid and bring its agreement back to active if nothing is overdue. */
    public function markPaid(Invoice $invoice): Invoice
    {
        $invoice->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $this->restoreIfSettled($invoice->organisation_agreement_id);

        return $invoice;
    }

    /** past_due -> active once the agreement has no overdue invoice left. */
    public function restoreIfSettled(int $agreementId): void
    {
        DB::transaction(function () use ($agreementId) {
            $agreement = OrganisationAgreement::query()->lockForUpdate()->find($agreementId);

            if (! $agreement || $agreement->status !== 'past_due') {
                return;
            }

            $stillOverdue = Invoice::query()
                ->where('organisation_agreement_id', $agreement->id)
                ->where('status', 'overdue')
                ->exists();

            if (! $stillOverdue) {
                $agreement->forceFill(['status' => 'active'])->save();
            }
        });
    }
}
