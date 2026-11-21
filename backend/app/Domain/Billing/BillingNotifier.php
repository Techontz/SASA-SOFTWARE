<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\SasaNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Tells an organisation's billing users about its invoices: everyone with an
 * active membership, on one of the organisation's projects, in a role that
 * holds organisation.billing.
 *
 * Billing is organisation level, not project level, so this does not go
 * through the per-project notification rules. In SASA always; by e-mail when
 * sasa.billing.invoice_email is on. A delivery failure is logged and never
 * fails the billing work: the invoice already exists.
 */
final class BillingNotifier
{
    public const PERMISSION = 'organisation.billing';

    public function invoiceIssued(Invoice $invoice, bool $autoPayStarted, ?string $autoPayPhone): int
    {
        $body = sprintf(
            'Invoice %s for %s covers %s to %s and is due on %s.',
            $invoice->number,
            self::money($invoice),
            $invoice->period_start->format('j M Y'),
            $invoice->period_end->format('j M Y'),
            $invoice->due_on->format('j M Y'),
        );

        $body .= $autoPayStarted
            ? ' Auto-pay has sent a mobile money prompt to '.$autoPayPhone.'. Approve it on that phone with your PIN to pay. Nothing is taken without that approval.'
            : ' You can pay it by mobile money or card from your organisation\'s plan page.';

        return $this->send($invoice, 'billing.invoice_issued', "Invoice {$invoice->number} is ready", $body, 'info');
    }

    public function invoiceOverdue(Invoice $invoice): int
    {
        $body = sprintf(
            'Invoice %s for %s was due on %s and has not been paid. Please pay it from your organisation\'s plan page.',
            $invoice->number,
            self::money($invoice),
            $invoice->due_on->format('j M Y'),
        );

        return $this->send($invoice, 'billing.invoice_overdue', "Invoice {$invoice->number} is overdue", $body, 'warning');
    }

    /** @return Collection<int,User> */
    public function recipients(int $organisationId): Collection
    {
        $userIds = ProjectMembership::query()
            ->where('organisation_id', $organisationId)
            ->where('status', 'active')
            ->whereHas('project', fn ($q) => $q->where('organisation_id', $organisationId))
            ->whereHas('role.permissions', fn ($q) => $q->where('key', self::PERMISSION))
            ->pluck('user_id')
            ->unique()
            ->all();

        if ($userIds === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->get();
    }

    private function send(Invoice $invoice, string $event, string $subject, string $body, string $severity): int
    {
        $recipients = $this->recipients($invoice->organisation_id);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $payload = [
            'invoice' => $invoice->public_id,
            'invoice_number' => $invoice->number,
            'url' => '/subscription',
        ];

        $channels = [['in_app']];

        if (config('sasa.billing.invoice_email')) {
            $channels[] = ['email'];
        }

        // One delivery per channel, so a mail failure cannot lose the in-app one.
        foreach ($channels as $via) {
            try {
                Notification::send($recipients, new SasaNotification(
                    eventKey: $event,
                    subject: $subject,
                    body: $body,
                    payload: $payload,
                    channels: $via,
                    severity: $severity,
                ));
            } catch (\Throwable $e) {
                Log::warning('billing.notification_failed', [
                    'event' => $event,
                    'invoice' => $invoice->number,
                    'channel' => $via[0],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $recipients->count();
    }

    private static function money(Invoice $invoice): string
    {
        return $invoice->currency.' '.number_format((float) $invoice->amount, 0);
    }
}
