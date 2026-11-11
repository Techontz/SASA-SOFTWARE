<?php

namespace App\Domain\Pap;

use App\Models\Pap;
use App\Models\Pap\PapAsset;
use App\Models\Pap\PapCrop;
use App\Models\Pap\PapDocument;
use App\Models\Pap\PapEntitlement;
use App\Models\Pap\PapHouseholdSurvey;
use App\Models\Pap\PapLandParcel;
use App\Models\Pap\PapLivelihood;
use App\Models\Pap\PapPayment;
use App\Models\Pap\PapRecord;
use App\Models\Pap\PapRelocation;
use App\Models\Pap\PapValuation;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Writes the child sections of a PAP (Parts B–J and M) and computes their
 * derived values. A derived value is never typed: it is recalculated from its
 * inputs on every save, so the register cannot disagree with itself.
 */
final class PapRecordService
{
    public function create(Pap $pap, string $section, array $data): PapRecord
    {
        $class = PapSections::get($section)['model'];

        return DB::transaction(function () use ($pap, $class, $data) {
            /** @var PapRecord $record */
            $record = new $class;
            $record->organisation_id = $pap->organisation_id;
            $record->project_id = $pap->project_id;
            $record->pap_id = $pap->id;
            $record->fill(collect($data)->only($class::INPUT_FIELDS)->all());

            if ($record instanceof PapHouseholdSurvey && blank($record->household_id)) {
                $record->household_id = $pap->household_id;
            }

            $this->derive($record);
            $record->save();

            return $record->fresh();
        });
    }

    public function update(PapRecord $record, array $data): PapRecord
    {
        return DB::transaction(function () use ($record, $data) {
            $record->fill(collect($data)->only($record::INPUT_FIELDS)->all());
            $this->derive($record);
            $record->save();

            return $record->fresh();
        });
    }

    public function archive(PapRecord $record): void
    {
        $record->archive();
    }

    public function derive(PapRecord $record): void
    {
        $this->applyDefaults($record);

        match (true) {
            $record instanceof PapLandParcel => $this->deriveLand($record),
            $record instanceof PapAsset => $this->deriveAsset($record),
            $record instanceof PapCrop => $this->deriveCrop($record),
            $record instanceof PapValuation => $this->deriveValuation($record),
            $record instanceof PapEntitlement => $record->value = $this->product($record->quantity, $record->unit_rate),
            $record instanceof PapPayment => $this->derivePayment($record),
            $record instanceof PapLivelihood => $this->checkLivelihood($record),
            $record instanceof PapDocument => $this->checkDates($record->issue_date, $record->expiry_date, 'The expiry date cannot be before the issue date.'),
            default => null,
        };
    }

    /** Columns that may not be empty fall back to their defaults rather than failing. */
    private function applyDefaults(PapRecord $record): void
    {
        $defaults = match (true) {
            $record instanceof PapPayment => ['acknowledgement_signed' => false],
            $record instanceof PapRelocation => [
                'relocation_required' => true, 'moving_assistance_provided' => false,
                'transit_assistance_provided' => false, 'relocation_status' => 'planned',
            ],
            $record instanceof PapEntitlement => ['entitlement_status' => 'proposed'],
            default => [],
        };

        foreach ($defaults as $field => $default) {
            if ($record->{$field} === null || $record->{$field} === '') {
                $record->{$field} = $default;
            }
        }
    }

    private function deriveLand(PapLandParcel $land): void
    {
        $total = $land->total_plot_size_ha !== null ? (float) $land->total_plot_size_ha : null;
        $affected = $land->affected_area_ha !== null ? (float) $land->affected_area_ha : null;

        if ($total !== null && $affected !== null && $affected > $total) {
            throw new DomainRuleException('The affected area cannot be larger than the whole plot.', 'affected_exceeds_total');
        }

        $land->percentage_affected = ($total && $affected !== null)
            ? round($affected / $total * 100, 2)
            : null;
    }

    private function deriveAsset(PapAsset $asset): void
    {
        if ($asset->length !== null && $asset->width !== null) {
            $asset->area = round((float) $asset->length * (float) $asset->width, 2);
        }
    }

    /** Compensation = plants × rate, or hectares × rate when the rate is per hectare. */
    private function deriveCrop(PapCrop $crop): void
    {
        $crop->rate_basis = $crop->rate_basis ?: ($crop->number_of_plants === null && $crop->area_cultivated !== null ? 'per_hectare' : 'per_plant');

        $quantity = $crop->rate_basis === 'per_hectare' ? $crop->area_cultivated : $crop->number_of_plants;

        $crop->compensation_amount = $this->product($quantity, $crop->unit_rate);
    }

    private function deriveValuation(PapValuation $valuation): void
    {
        $total = 0.0;

        foreach (PapValuation::COMPONENTS as $component) {
            $valuation->{$component} = $valuation->{$component} ?? 0;
            $total += (float) $valuation->{$component};
        }

        $valuation->total_compensation_value = round($total, 2);
        $valuation->approval_status = $valuation->approval_status ?: 'draft';

        if ($valuation->approval_status === 'approved' && $valuation->approval_date === null) {
            $valuation->approval_date = today();
        }
    }

    /**
     * Outstanding = approved − paid. The status follows the money unless an
     * officer has deliberately put the payment on hold or in dispute.
     */
    private function derivePayment(PapPayment $payment): void
    {
        $approved = (float) ($payment->amount_approved ?? 0);
        $paid = (float) ($payment->amount_paid ?? 0);

        if ($paid > $approved) {
            throw new DomainRuleException('The amount paid cannot be more than the amount approved.', 'overpayment');
        }

        $payment->amount_approved = $approved;
        $payment->amount_paid = $paid;
        $payment->outstanding_amount = round($approved - $paid, 2);

        if (! in_array($payment->payment_status, ['on_hold', 'disputed'], true)) {
            $payment->payment_status = match (true) {
                $approved > 0 && $paid >= $approved => 'paid',
                $paid > 0 => 'partially_paid',
                default => 'pending',
            };
        }

        if ($paid > 0 && $payment->payment_date === null) {
            $payment->payment_date = today();
        }
    }

    private function checkLivelihood(PapLivelihood $livelihood): void
    {
        $this->checkDates($livelihood->date_enrolled, $livelihood->date_completed, 'The completion date cannot be before enrolment.');

        $visits = collect($livelihood->monitoring_visits ?? [])
            ->map(fn ($visit) => [
                'visit_date' => $visit['visit_date'] ?? null,
                'visited_by' => $visit['visited_by'] ?? null,
                'findings' => $visit['findings'] ?? null,
            ])
            ->filter(fn ($visit) => $visit['visit_date'])
            ->sortBy('visit_date')
            ->values()
            ->all();

        $livelihood->monitoring_visits = $visits;
        $livelihood->programme_status = $livelihood->programme_status ?: 'not_started';
    }

    private function checkDates(mixed $from, mixed $to, string $message): void
    {
        if ($from && $to && $to < $from) {
            throw new DomainRuleException($message, 'date_order');
        }
    }

    private function product(mixed $quantity, mixed $rate): ?float
    {
        if ($quantity === null || $rate === null) {
            return null;
        }

        return round((float) $quantity * (float) $rate, 2);
    }
}
