<?php

namespace App\Domain\Pap;

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
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The child sections of a PAP record (Parts B–J and M). One definition per
 * section: its model, the policy ability that guards writes, the option lists
 * that label its values, and its validation rules.
 *
 * Derived fields (percentage affected, compensation amount, value, total,
 * outstanding) never appear in the rules: they are computed by
 * PapRecordService, never accepted from a request.
 */
final class PapSections
{
    /** @return array<string,array{model:class-string<PapRecord>,label:string,ability:string,relation:string,labels:array<string,string>}> */
    public static function all(): array
    {
        return [
            'household-surveys' => [
                'model' => PapHouseholdSurvey::class, 'label' => 'Household socio-economic survey', 'ability' => 'update', 'relation' => 'householdSurveys',
                'labels' => ['education_level' => 'education_levels', 'poverty_category' => 'poverty_categories', 'access_to_electricity' => 'electricity_access', 'access_to_water' => 'water_access', 'access_to_sanitation' => 'sanitation_access'],
            ],
            'land' => [
                'model' => PapLandParcel::class, 'label' => 'Land inventory', 'ability' => 'update', 'relation' => 'landParcels',
                'labels' => ['land_use' => 'land_uses', 'ownership_type' => 'land_ownership_types', 'land_status' => 'land_statuses'],
            ],
            'assets' => [
                'model' => PapAsset::class, 'label' => 'Asset inventory', 'ability' => 'update', 'relation' => 'assets',
                'labels' => ['asset_category' => 'asset_categories', 'condition' => 'asset_conditions', 'affected_status' => 'asset_affected_statuses', 'unit' => 'asset_units'],
            ],
            'crops' => [
                'model' => PapCrop::class, 'label' => 'Crop and tree inventory', 'ability' => 'update', 'relation' => 'crops',
                'labels' => ['productive_status' => 'productive_statuses', 'rate_basis' => 'crop_rate_bases'],
            ],
            'valuations' => [
                'model' => PapValuation::class, 'label' => 'Valuation register', 'ability' => 'update', 'relation' => 'valuations',
                'labels' => ['approval_status' => 'valuation_approval_statuses'],
            ],
            'entitlements' => [
                'model' => PapEntitlement::class, 'label' => 'Entitlement register', 'ability' => 'update', 'relation' => 'entitlements',
                'labels' => ['entitlement_type' => 'entitlement_types', 'entitlement_status' => 'entitlement_statuses'],
            ],
            'payments' => [
                'model' => PapPayment::class, 'label' => 'Compensation payment register', 'ability' => 'managePayments', 'relation' => 'payments',
                'labels' => ['payment_status' => 'payment_statuses', 'payment_method' => 'payment_methods'],
            ],
            'relocations' => [
                'model' => PapRelocation::class, 'label' => 'Relocation register', 'ability' => 'update', 'relation' => 'relocations',
                'labels' => ['relocation_status' => 'relocation_statuses'],
            ],
            'livelihoods' => [
                'model' => PapLivelihood::class, 'label' => 'Livelihood restoration', 'ability' => 'update', 'relation' => 'livelihoods',
                'labels' => ['programme_status' => 'livelihood_statuses', 'income_restored' => 'income_restored_options'],
            ],
            'documents' => [
                'model' => PapDocument::class, 'label' => 'Document register', 'ability' => 'update', 'relation' => 'documents',
                'labels' => ['document_type' => 'document_types'],
            ],
        ];
    }

    /** @return array{model:class-string<PapRecord>,label:string,ability:string,relation:string,labels:array<string,string>} */
    public static function get(string $section): array
    {
        return self::all()[$section] ?? throw new NotFoundHttpException;
    }

    public static function forModel(PapRecord $record): string
    {
        foreach (self::all() as $key => $definition) {
            if ($record instanceof $definition['model']) {
                return $key;
            }
        }

        throw new \InvalidArgumentException('Unknown PAP section for '.$record::class);
    }

    /**
     * @param  callable(string):array<int,string>  $in  option list key => allowed values
     * @return array<string,array<int,mixed>>
     */
    public static function rules(string $section, callable $in, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $text = fn (int $max = 255) => ['nullable', 'string', "max:$max"];
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999999'];
        $option = fn (string $list) => ['nullable', Rule::in($in($list))];
        $date = ['nullable', 'date'];

        return match ($section) {
            'household-surveys' => [
                'household_id' => $text(40),
                'survey_date' => $date,
                'household_head' => $text(160),
                'household_size' => ['nullable', 'integer', 'min:0', 'max:200'],
                'number_of_adults' => ['nullable', 'integer', 'min:0', 'max:200'],
                'number_of_children' => ['nullable', 'integer', 'min:0', 'max:200'],
                'education_level' => $option('education_levels'),
                'primary_occupation' => $text(120),
                'secondary_occupation' => $text(120),
                'monthly_household_income' => $money,
                'main_income_source' => $text(120),
                'asset_ownership_score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
                'poverty_category' => $option('poverty_categories'),
                'livestock_owned' => $text(2000),
                'access_to_electricity' => $option('electricity_access'),
                'access_to_water' => $option('water_access'),
                'access_to_sanitation' => $option('sanitation_access'),
                'comments' => $text(5000),
            ],
            'land' => [
                'plot_number' => [$required, 'string', 'max:60'],
                'village' => $text(120),
                'land_use' => $option('land_uses'),
                'ownership_type' => $option('land_ownership_types'),
                'ccro_number' => $text(60),
                'title_number' => $text(60),
                'total_plot_size_ha' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
                'affected_area_ha' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
                'gps_easting' => ['nullable', 'numeric'],
                'gps_northing' => ['nullable', 'numeric'],
                'map_reference' => $text(120),
                'land_status' => $option('land_statuses'),
                'remarks' => $text(5000),
            ],
            'assets' => [
                'asset_category' => [$required, Rule::in($in('asset_categories'))],
                'asset_type' => $text(120),
                'description' => $text(5000),
                'length' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
                'width' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
                'area' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
                'quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
                'unit' => $option('asset_units'),
                'condition' => $option('asset_conditions'),
                'affected_status' => $option('asset_affected_statuses'),
                'photo_reference' => $text(160),
                'remarks' => $text(5000),
            ],
            'crops' => [
                'plot_number' => $text(60),
                'crop_type' => [$required, 'string', 'max:120'],
                'number_of_plants' => ['nullable', 'integer', 'min:0', 'max:100000000'],
                'area_cultivated' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
                'tree_species' => $text(120),
                'tree_age' => ['nullable', 'integer', 'min:0', 'max:500'],
                'productive_status' => $option('productive_statuses'),
                'rate_basis' => $option('crop_rate_bases'),
                'unit_rate' => $money,
                'remarks' => $text(5000),
            ],
            'valuations' => array_merge([
                'valuation_date' => $date,
                'valuer_name' => $text(160),
                'valuation_report_number' => $text(80),
                'approval_status' => $option('valuation_approval_statuses'),
                'approval_date' => $date,
            ], array_fill_keys(PapValuation::COMPONENTS, $money)),
            'entitlements' => [
                'entitlement_type' => [$required, Rule::in($in('entitlement_types'))],
                'description' => $text(5000),
                'quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
                'unit_rate' => $money,
                'approval_date' => $date,
                'entitlement_status' => $option('entitlement_statuses'),
                'remarks' => $text(5000),
            ],
            'payments' => [
                'compensation_agreement_number' => $text(80),
                'bank_name' => $text(120),
                'bank_account_number' => $text(60),
                'mobile_money_number' => $text(40),
                'amount_approved' => array_merge([$required], array_slice($money, 1)),
                'amount_paid' => $money,
                'payment_date' => $date,
                'payment_method' => $option('payment_methods'),
                'receipt_number' => $text(80),
                'acknowledgement_signed' => ['nullable', 'boolean'],
                'witness_name' => $text(160),
                'payment_status' => $option('payment_statuses'),
                'comments' => $text(5000),
            ],
            'relocations' => [
                'relocation_required' => ['nullable', 'boolean'],
                'replacement_plot_number' => $text(60),
                'replacement_house_number' => $text(60),
                'house_handover_date' => $date,
                'moving_date' => $date,
                'moving_assistance_provided' => ['nullable', 'boolean'],
                'transit_assistance_provided' => ['nullable', 'boolean'],
                'relocation_status' => $option('relocation_statuses'),
                'remarks' => $text(5000),
            ],
            'livelihoods' => [
                'income_source_lost' => $text(160),
                'livelihood_program' => [$required, 'string', 'max:160'],
                'training_provided' => $text(2000),
                'grant_amount' => $money,
                'business_startup_support' => $text(2000),
                'agricultural_support' => $text(2000),
                'date_enrolled' => $date,
                'date_completed' => $date,
                'monitoring_visits' => ['nullable', 'array', 'max:50'],
                'monitoring_visits.*.visit_date' => ['required', 'date'],
                'monitoring_visits.*.visited_by' => ['nullable', 'string', 'max:160'],
                'monitoring_visits.*.findings' => ['nullable', 'string', 'max:2000'],
                'income_restored' => $option('income_restored_options'),
                'programme_status' => $option('livelihood_statuses'),
            ],
            'documents' => [
                'document_type' => [$required, Rule::in($in('document_types'))],
                'document_number' => $text(80),
                'issue_date' => $date,
                'expiry_date' => $date,
                'digital_file_name' => $text(255),
                'storage_location' => $text(255),
                'verified_by' => $text(160),
                'verification_date' => $date,
            ],
            default => throw new NotFoundHttpException,
        };
    }
}
