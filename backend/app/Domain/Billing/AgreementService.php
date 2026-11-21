<?php

namespace App\Domain\Billing;

use App\Models\Organisation;
use App\Models\OrganisationAgreement;
use App\Models\Project;
use App\Models\User;
use App\Support\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agreed plans. SASA does not publish prices: the SASA team agrees a monthly
 * price and a scope with each company, records it here, and the billing cycle
 * (InvoiceService) invoices it every month.
 *
 * An organisation has at most one current agreement (not archived, not
 * cancelled). A change of price or scope applies to invoices not yet issued;
 * an issued invoice keeps the amount it was issued for.
 *
 * Every change is audited by the Auditable trait (agreement.created,
 * agreement.updated, agreement.status_changed).
 */
final class AgreementService
{
    /** @param array<string,mixed> $data */
    public function create(array $data, User $admin): OrganisationAgreement
    {
        return DB::transaction(function () use ($data, $admin) {
            $organisation = Organisation::query()->lockForUpdate()->findOrFail((int) $data['organisation_id']);
            $this->assertNoCurrentAgreement($organisation->id);

            $startsOn = Carbon::parse((string) $data['starts_on'])->startOfDay();

            $agreement = new OrganisationAgreement;
            $agreement->fill([
                'organisation_id' => $organisation->id,
                'enquiry_id' => $data['enquiry_id'] ?? null,
                'name' => trim((string) $data['name']),
                'currency' => 'TZS', // Snippe settles TZS only
                'monthly_amount' => $data['monthly_amount'],
                'billing_day' => (int) $data['billing_day'],
                'starts_on' => $startsOn,
                'next_due_on' => $startsOn,
                'modules' => self::normaliseModules($data['modules'] ?? []),
                'limits' => self::normaliseLimits($data['limits'] ?? null),
                'terms_note' => self::nullableText($data['terms_note'] ?? null),
                'internal_note' => self::nullableText($data['internal_note'] ?? null),
            ]);
            $agreement->public_id = (string) Str::ulid();
            $agreement->status = 'active';
            $agreement->auto_pay = false;
            $agreement->created_by = $admin->id;
            $agreement->save();

            return $agreement->fresh(['organisation:id,name']);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(OrganisationAgreement $agreement, array $data): OrganisationAgreement
    {
        return DB::transaction(function () use ($agreement, $data) {
            /** @var OrganisationAgreement $agreement */
            $agreement = OrganisationAgreement::query()->lockForUpdate()->findOrFail($agreement->id);

            if ($agreement->status === 'cancelled') {
                throw new DomainRuleException('This agreement was cancelled and can no longer be changed. Create a new one instead.', 'agreement_closed');
            }

            $hasInvoices = $agreement->invoices()->exists();

            if (array_key_exists('organisation_id', $data) && (int) $data['organisation_id'] !== $agreement->organisation_id) {
                if ($hasInvoices) {
                    throw $this->fieldError('organisation_id', 'This agreement already has invoices, so it cannot move to another organisation.', 'agreement_has_invoices');
                }

                $organisation = Organisation::query()->lockForUpdate()->findOrFail((int) $data['organisation_id']);
                $this->assertNoCurrentAgreement($organisation->id);
                $agreement->organisation_id = $organisation->id;
            }

            if (array_key_exists('starts_on', $data)) {
                $startsOn = Carbon::parse((string) $data['starts_on'])->startOfDay();

                if (! $startsOn->equalTo($agreement->starts_on)) {
                    if ($hasInvoices) {
                        throw $this->fieldError('starts_on', 'The start date cannot change once the first invoice has been issued.', 'agreement_has_invoices');
                    }

                    $agreement->starts_on = $startsOn;
                    $agreement->next_due_on = $startsOn;
                }
            }

            foreach (['name', 'monthly_amount', 'billing_day'] as $key) {
                if (array_key_exists($key, $data)) {
                    $agreement->{$key} = $key === 'name' ? trim((string) $data[$key]) : $data[$key];
                }
            }

            if (array_key_exists('enquiry_id', $data)) {
                $agreement->enquiry_id = $data['enquiry_id'];
            }

            if (array_key_exists('modules', $data)) {
                $agreement->modules = self::normaliseModules($data['modules']);
            }

            if (array_key_exists('limits', $data)) {
                $agreement->limits = self::normaliseLimits($data['limits']);
            }

            foreach (['terms_note', 'internal_note'] as $key) {
                if (array_key_exists($key, $data)) {
                    $agreement->{$key} = self::nullableText($data[$key]);
                }
            }

            // active | suspended | cancelled, by a person. past_due is set by
            // the billing cycle; a person may clear it by setting active.
            if (array_key_exists('status', $data) && $data['status'] !== null) {
                $agreement->status = (string) $data['status'];
            }

            $agreement->save();

            return $agreement->fresh(['organisation:id,name']);
        });
    }

    /**
     * The customer turns auto-pay on or off. "Auto-pay" means: when the
     * billing cycle raises an invoice, it immediately sends a mobile money
     * prompt to this phone. The payer still approves it with their PIN;
     * nothing is ever debited silently.
     */
    public function setAutoPay(OrganisationAgreement $agreement, bool $enabled, ?string $phone): OrganisationAgreement
    {
        return DB::transaction(function () use ($agreement, $enabled, $phone) {
            /** @var OrganisationAgreement $agreement */
            $agreement = OrganisationAgreement::query()->lockForUpdate()->findOrFail($agreement->id);

            $normalised = PhoneNumber::normaliseTanzanian($phone);

            if ($enabled && $normalised === null && $agreement->auto_pay_phone === null) {
                throw $this->fieldError('phone', 'Enter the Tanzanian mobile number that will receive the monthly payment prompt.', 'invalid_phone');
            }

            $agreement->auto_pay = $enabled;

            if ($normalised !== null) {
                $agreement->auto_pay_phone = $normalised;
            }

            $agreement->save();

            return $agreement->fresh();
        });
    }

    /** The organisation's current agreement (not archived, not cancelled), if any. */
    public function currentFor(int $organisationId): ?OrganisationAgreement
    {
        return OrganisationAgreement::query()
            ->current()
            ->where('organisation_id', $organisationId)
            ->orderByDesc('id')
            ->first();
    }

    /** @return array{projects:int,users:int} what the organisation uses today, to compare with its limits */
    public function usage(int $organisationId): array
    {
        return [
            'projects' => Project::query()
                ->where('organisation_id', $organisationId)
                ->whereNull('archived_at')
                ->where('status', '!=', 'archived')
                ->count(),
            'users' => User::query()
                ->where('organisation_id', $organisationId)
                ->whereNull('archived_at')
                ->where('status', 'active')
                ->count(),
        ];
    }

    /** @return array<int,string> known keys, once each, in catalogue order */
    public static function normaliseModules(mixed $modules): array
    {
        return array_values(array_intersect(OrganisationAgreement::MODULES, (array) $modules));
    }

    /** @return array{projects:int|null,users:int|null} */
    public static function normaliseLimits(mixed $limits): array
    {
        $limits = is_array($limits) ? $limits : [];

        return [
            'projects' => isset($limits['projects']) && $limits['projects'] !== '' ? (int) $limits['projects'] : null,
            'users' => isset($limits['users']) && $limits['users'] !== '' ? (int) $limits['users'] : null,
        ];
    }

    private function assertNoCurrentAgreement(int $organisationId): void
    {
        $existing = OrganisationAgreement::query()->current()->where('organisation_id', $organisationId)->first();

        if ($existing) {
            throw $this->fieldError(
                'organisation_id',
                "This organisation already has an agreement ({$existing->name}). Change that one, or cancel it first.",
                'agreement_exists',
            );
        }
    }

    private function fieldError(string $field, string $message, string $code): DomainRuleException
    {
        return new DomainRuleException($message, $code, 422, ['errors' => [$field => [$message]]]);
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
