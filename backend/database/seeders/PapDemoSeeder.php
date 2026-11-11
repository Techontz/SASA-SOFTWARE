<?php

namespace Database\Seeders;

use App\Domain\Grievance\IntakeEngine;
use App\Domain\Pap\PapConsultationService;
use App\Domain\Pap\PapRecordService;
use App\Domain\Pap\PapService;
use App\Models\GrievanceCategory;
use App\Models\Location;
use App\Models\Pap;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * A resettlement register in mid-flight on the demo transmission line:
 * twelve PAPs across four villages, surveyed, inventoried and valued, with
 * payments in every state, two households relocated, a livelihood programme
 * under way and three consultation meetings.
 *
 * Every PAP is created through PapService, so each one lands in the
 * stakeholder register through the same sync path a field officer's entry does.
 */
class PapDemoSeeder extends Seeder
{
    public function run(
        PapService $paps,
        PapRecordService $records,
        PapConsultationService $consultations,
        IntakeEngine $intake,
    ): void {
        $project = Project::where('code', 'NBT-400KV')->first();

        if (! $project) {
            $this->command?->warn('Demo project NBT-400KV not found — run DemoSeeder first.');

            return;
        }

        if (Pap::acrossTenants()->where('project_id', $project->id)->exists()) {
            $this->command?->warn('PAP demo data already present — skipping.');

            return;
        }

        $users = User::whereIn('email', ['cro@sasa.test', 'project.admin@sasa.test', 'grievance@sasa.test', 'field@sasa.test'])
            ->get()->keyBy('email');

        $villages = Location::where('organisation_id', $project->organisation_id)
            ->where('level', 'village')->orderBy('id')->limit(4)->get()->values();

        if ($villages->isEmpty()) {
            $this->command?->warn('No villages in the location hierarchy — skipping PAP demo data.');

            return;
        }

        if ($users->has('cro@sasa.test')) {
            Auth::login($users['cro@sasa.test']);
        }

        // [name, gender, dob, marital, vulnerability, village index, kitongoji, joins household of index]
        $people = [
            ['Neema Charles Mwita', 'female', '1979-03-14', 'widowed', 'female_headed_household', 0, 'Mwembeni', null],
            ['Juma Charles Mwita', 'male', '2001-08-02', 'single', 'none', 0, 'Mwembeni', 0],
            ['Mzee Rashid Kiwelu', 'male', '1946-11-20', 'married', 'elderly_person', 0, 'Kati', null],
            ['Amina Juma Nkya', 'female', '1988-06-09', 'married', 'person_with_disability', 1, 'Mlimani', null],
            ['Hamisi Selemani Mollel', 'male', '1975-01-30', 'married', 'none', 1, 'Sokoni', null],
            ['Rehema Hamisi Mollel', 'female', '1980-04-18', 'married', 'none', 1, 'Sokoni', 4],
            ['Daudi Peter Masanja', 'male', '1968-09-05', 'divorced', 'chronically_ill', 2, 'Bondeni', null],
            ['Fatuma Ally Ngoyai', 'female', '1992-12-11', 'separated', 'extremely_poor_household', 2, 'Bondeni', null],
            ['Elias John Shija', 'male', '1983-02-27', 'married', 'none', 2, 'Majengo', null],
            ['Mariamu Said Kweka', 'female', '2004-07-16', 'single', 'orphan_headed_household', 3, 'Kilimahewa', null],
            ['Paulo Mathias Lyimo', 'male', '1971-05-03', 'married', 'none', 3, 'Kilimahewa', null],
            ['Grace Joseph Mwakyusa', 'female', '1990-10-22', 'married', 'none', 3, 'Shuleni', null],
        ];

        /** @var array<int,Pap> $created */
        $created = [];

        foreach ($people as $index => [$name, $gender, $dob, $marital, $vulnerability, $villageIndex, $kitongoji, $joins]) {
            $village = $villages[$villageIndex % $villages->count()];

            $created[$index] = $paps->create($project, [
                'full_name' => $name,
                'national_id' => sprintf('%s-%05d-%05d-%02d', str_replace('-', '', $dob), 41100 + $index, $index + 1, 20 + $index),
                'gender' => $gender,
                'date_of_birth' => $dob,
                'marital_status' => $marital,
                'phone' => sprintf('+255 7%02d %03d %03d', 54 + $index, 120 + $index * 7, 300 + $index * 11),
                'alternative_contact' => $index % 3 === 0 ? sprintf('+255 6%02d %03d %03d', 10 + $index, 400 + $index, 500 + $index) : null,
                'location_id' => $village->id,
                'kitongoji' => $kitongoji,
                'physical_address' => "Kitongoji cha {$kitongoji}, near the line corridor",
                'latitude' => $village->latitude ? (float) $village->latitude + $index * 0.001 : -4.30 - $index * 0.01,
                'longitude' => $village->longitude ? (float) $village->longitude + $index * 0.001 : 34.70 + $index * 0.01,
                'next_of_kin' => $index % 2 === 0 ? 'Spouse or eldest child' : 'Sibling',
                'next_of_kin_contact' => sprintf('+255 78%d %03d %03d', $index % 10, 200 + $index, 600 + $index),
                'vulnerability_category' => $vulnerability,
                'household_id' => $joins !== null ? $created[$joins]->household_id : null,
                'remarks' => $vulnerability !== 'none' ? 'Vulnerable PAP — prioritise for assistance and door-to-door engagement.' : null,
                'captured_at' => now()->subMonths(8)->addDays($index * 3),
            ]);
        }

        $paymentStatuses = ['paid', 'paid', 'partially_paid', 'pending', 'paid', 'on_hold', 'partially_paid', 'pending', 'disputed', 'paid', 'pending', 'partially_paid'];

        foreach ($created as $index => $pap) {
            $plot = sprintf('%s/%03d', strtoupper(substr($pap->village ?? 'NBT', 0, 3)), 100 + $index * 7);
            $total = round(0.8 + ($index % 5) * 0.6, 2);
            $affected = round($total * (0.15 + ($index % 4) * 0.2), 2);

            if ($index !== 1 && $index !== 5) { // dependants in a household share the head's plot
                $records->create($pap, 'household-surveys', [
                    'survey_date' => now()->subMonths(7)->toDateString(),
                    'household_head' => $pap->full_name,
                    'household_size' => 3 + $index % 6,
                    'number_of_adults' => 2 + $index % 2,
                    'number_of_children' => 1 + $index % 5,
                    'education_level' => ['primary', 'secondary', 'none', 'primary', 'vocational'][$index % 5],
                    'primary_occupation' => ['Farmer', 'Livestock keeper', 'Petty trader', 'Farmer'][$index % 4],
                    'secondary_occupation' => $index % 2 ? 'Casual labour' : null,
                    'monthly_household_income' => 180000 + $index * 35000,
                    'main_income_source' => ['Maize and beans', 'Cattle', 'Kiosk', 'Rice'][$index % 4],
                    'asset_ownership_score' => 3 + $index % 7,
                    'poverty_category' => $pap->isVulnerable() ? 'poor' : 'vulnerable_non_poor',
                    'livestock_owned' => $index % 3 === 0 ? '4 cattle, 10 goats, chickens' : '6 chickens',
                    'access_to_electricity' => $index % 4 === 0 ? 'solar' : 'none',
                    'access_to_water' => ['borehole', 'unprotected_source', 'protected_well'][$index % 3],
                    'access_to_sanitation' => ['traditional_pit', 'improved_pit'][$index % 2],
                ]);

                $records->create($pap, 'land', [
                    'plot_number' => $plot,
                    'village' => $pap->village,
                    'land_use' => ['agricultural', 'residential', 'mixed', 'agricultural'][$index % 4],
                    'ownership_type' => ['customary_occupant', 'owner', 'customary_occupant', 'tenant', 'informal_occupant'][$index % 5],
                    'ccro_number' => $index % 3 === 0 ? sprintf('CCRO/%d/%04d', 2019 + $index % 4, 310 + $index) : null,
                    'total_plot_size_ha' => $total,
                    'affected_area_ha' => $affected,
                    'gps_easting' => 512340 + $index * 145,
                    'gps_northing' => 9523410 + $index * 210,
                    'map_reference' => 'RAP strip map sheet '.(3 + intdiv($index, 4)),
                    'land_status' => $paymentStatuses[$index] === 'paid' ? 'compensated' : 'valued',
                ]);

                $records->create($pap, 'assets', [
                    'asset_category' => $index % 3 === 0 ? 'residential_structure' : 'fence',
                    'asset_type' => $index % 3 === 0 ? 'Burnt-brick house, iron sheet roof' : 'Live fence (euphorbia)',
                    'length' => $index % 3 === 0 ? 9 : 40 + $index * 3,
                    'width' => $index % 3 === 0 ? 7 : 1,
                    'quantity' => 1,
                    'unit' => $index % 3 === 0 ? 'm2' : 'm',
                    'condition' => ['good', 'fair', 'poor'][$index % 3],
                    'affected_status' => $index % 3 === 0 ? 'fully_affected' : 'partially_affected',
                    'photo_reference' => sprintf('IMG_%04d.jpg', 2100 + $index),
                ]);

                $records->create($pap, 'crops', [
                    'plot_number' => $plot,
                    'crop_type' => 'Mango',
                    'number_of_plants' => 4 + $index,
                    'tree_species' => 'Mangifera indica',
                    'tree_age' => 5 + $index % 8,
                    'productive_status' => 'mature_productive',
                    'rate_basis' => 'per_plant',
                    'unit_rate' => 45000,
                ]);
                $records->create($pap, 'crops', [
                    'plot_number' => $plot,
                    'crop_type' => 'Maize',
                    'area_cultivated' => $affected,
                    'productive_status' => 'immature',
                    'rate_basis' => 'per_hectare',
                    'unit_rate' => 1200000,
                ]);
            }

            $landValue = round($affected * 3500000, -3);
            $structure = $index % 3 === 0 ? 14500000 : 900000 + $index * 50000;
            $crops = (4 + $index) * 45000 + round($affected * 1200000, -3);

            $valuation = $records->create($pap, 'valuations', [
                'valuation_date' => now()->subMonths(5)->toDateString(),
                'valuer_name' => 'Chief Government Valuer\'s office — Mwanza zone',
                'valuation_report_number' => sprintf('CGV/NBT/%03d', 40 + $index),
                'land_value' => $landValue,
                'structure_value' => $structure,
                'crop_value' => $crops,
                'tree_value' => 0,
                'disturbance_allowance' => round(($landValue + $structure) * 0.08, -3),
                'transport_allowance' => $index % 3 === 0 ? 450000 : 0,
                'accommodation_allowance' => $index % 3 === 0 ? 1440000 : 0,
                'loss_of_profit_allowance' => $index === 7 ? 600000 : 0,
                'approval_status' => $index === 10 ? 'submitted' : 'approved',
                'approval_date' => $index === 10 ? null : now()->subMonths(4)->toDateString(),
            ]);

            $records->create($pap, 'entitlements', [
                'entitlement_type' => 'cash_compensation',
                'description' => 'Cash compensation per the approved valuation.',
                'quantity' => 1,
                'unit_rate' => (float) $valuation->total_compensation_value,
                'approval_date' => $index === 10 ? null : now()->subMonths(4)->toDateString(),
                'entitlement_status' => $index === 10 ? 'proposed' : 'approved',
            ]);

            if ($index === 10) {
                continue; // valuation not yet approved: no payment yet
            }

            $approved = (float) $valuation->total_compensation_value;
            $status = $paymentStatuses[$index];
            $paid = match ($status) {
                'paid' => $approved,
                'partially_paid' => round($approved * 0.5, -3),
                default => 0,
            };

            $records->create($pap, 'payments', [
                'compensation_agreement_number' => sprintf('NBT/CA/%04d', 200 + $index),
                'bank_name' => $index % 2 ? 'CRDB Bank' : 'NMB Bank',
                'bank_account_number' => $index % 4 === 3 ? null : sprintf('0150%08d', 3301200 + $index * 17),
                'mobile_money_number' => $index % 4 === 3 ? $pap->phone : null,
                'amount_approved' => $approved,
                'amount_paid' => $paid,
                'payment_date' => $paid > 0 ? now()->subMonths(2)->addDays($index)->toDateString() : null,
                'payment_method' => $index % 4 === 3 ? 'mobile_money' : 'bank_transfer',
                'receipt_number' => $paid > 0 ? sprintf('RCT-%05d', 8800 + $index) : null,
                'acknowledgement_signed' => $paid > 0,
                'witness_name' => $paid > 0 ? 'Village Executive Officer' : null,
                'payment_status' => in_array($status, ['on_hold', 'disputed'], true) ? $status : null,
                'comments' => match ($status) {
                    'on_hold' => 'Held pending probate — the registered owner is deceased.',
                    'disputed' => 'PAP disputes the plot boundary; referred to the grievance committee.',
                    default => null,
                },
            ]);
        }

        // Two households relocated, one in transit.
        foreach ([[0, 'relocated'], [2, 'relocated'], [3, 'house_handed_over']] as [$index, $status]) {
            $records->create($created[$index], 'relocations', [
                'relocation_required' => true,
                'replacement_plot_number' => sprintf('RSA/%03d', 10 + $index),
                'replacement_house_number' => sprintf('H-%02d', 3 + $index),
                'house_handover_date' => now()->subMonths(3)->toDateString(),
                'moving_date' => $status === 'relocated' ? now()->subMonths(2)->toDateString() : null,
                'moving_assistance_provided' => $status === 'relocated',
                'transit_assistance_provided' => $status === 'relocated',
                'relocation_status' => $status,
            ]);
        }

        // Livelihood restoration for the most affected and the vulnerable.
        foreach ([[0, 'Poultry keeping', 'completed', 'yes'], [3, 'Tailoring and small business', 'active', 'partially'],
            [6, 'Improved maize agronomy', 'needs_follow_up', 'no'], [7, 'Village savings group (VSLA)', 'active', 'partially'],
            [9, 'Beekeeping', 'not_started', null]] as [$index, $programme, $status, $restored]) {
            $enrolled = now()->subMonths(6);
            $visits = $status === 'not_started' ? [] : [
                ['visit_date' => $enrolled->copy()->addMonths(1)->toDateString(), 'visited_by' => 'Livelihood officer', 'findings' => 'Inputs delivered; training attended.'],
                ['visit_date' => $enrolled->copy()->addMonths(3)->toDateString(), 'visited_by' => 'Livelihood officer', 'findings' => 'Activity running; first sales recorded.'],
            ];

            if ($status === 'completed') {
                $visits[] = ['visit_date' => $enrolled->copy()->addMonths(5)->toDateString(), 'visited_by' => 'M&E officer', 'findings' => 'Income at or above pre-project baseline.'];
            }

            $records->create($created[$index], 'livelihoods', [
                'income_source_lost' => 'Crop sales from the affected plot',
                'livelihood_program' => $programme,
                'training_provided' => 'Five-day practical training with a district extension officer',
                'grant_amount' => 750000,
                'business_startup_support' => $index === 3 ? 'Sewing machine and start-up fabric' : null,
                'agricultural_support' => $index === 6 ? 'Improved seed and fertiliser for two seasons' : null,
                'date_enrolled' => $enrolled->toDateString(),
                'date_completed' => $status === 'completed' ? now()->subMonth()->toDateString() : null,
                'monitoring_visits' => $visits,
                'income_restored' => $restored,
                'programme_status' => $status,
            ]);
        }

        foreach ([0, 2, 4, 6, 8] as $index) {
            $records->create($created[$index], 'documents', [
                'document_type' => 'nida_card',
                'document_number' => $created[$index]->national_id,
                'issue_date' => now()->subYears(4)->toDateString(),
                'digital_file_name' => sprintf('%s-nida.pdf', $created[$index]->reference),
                'storage_location' => 'RAP office, cabinet 2',
                'verified_by' => 'Community relations officer',
                'verification_date' => now()->subMonths(7)->toDateString(),
            ]);
            $records->create($created[$index], 'documents', [
                'document_type' => 'compensation_agreement',
                'document_number' => sprintf('NBT/CA/%04d', 200 + $index),
                'issue_date' => now()->subMonths(4)->toDateString(),
                'digital_file_name' => sprintf('%s-agreement.pdf', $created[$index]->reference),
                'storage_location' => 'RAP office, cabinet 2',
            ]);
        }

        foreach ([
            [-150, 0, 'village_assembly', 'Project disclosure and cut-off date for eligibility', 64, 58, 12,
                'Fear that people arriving after the census will be excluded; questions on the cut-off date.',
                'Cut-off date announced and posted at the village office; census list to be displayed for 14 days.'],
            [-110, 1, 'valuation_disclosure', 'Disclosure of valuation rates for land, crops and structures', 41, 47, 9,
                'Mango tree rates considered too low; women asked to be named on payment for jointly owned land.',
                'Valuer to explain the rate basis; spousal consent and joint accounts offered for household land.'],
            [-40, 2, 'focus_group', 'Livelihood restoration options for vulnerable households', 8, 19, 15,
                'Elderly and disabled PAPs cannot travel to the district for training.',
                'Training to be held in the village; transport allowance for those who must travel.'],
        ] as [$days, $villageIndex, $type, $topic, $male, $female, $vulnerable, $concerns, $actions]) {
            $village = $villages[$villageIndex % $villages->count()];

            $consultations->create($project, [
                'consultation_date' => now()->addDays($days)->toDateString(),
                'location_id' => $village->id,
                'meeting_type' => $type,
                'topic_discussed' => $topic,
                'facilitator' => 'Community relations team with the Village Executive Officer',
                'number_male' => $male,
                'number_female' => $female,
                'number_vulnerable' => $vulnerable,
                'attendance_sheet_reference' => sprintf('ATT/%s/%s', strtoupper(substr($village->name, 0, 3)), now()->addDays($days)->format('Ymd')),
                'key_concerns_raised' => $concerns,
                'actions_agreed' => $actions,
            ]);
        }

        // Part K lives in the grievance module: a few cases linked to PAPs.
        $categories = GrievanceCategory::where('project_id', $project->id)->get()->keyBy('key');
        $category = $categories['land_assets_livelihoods'] ?? $categories->first();

        if ($users->has('grievance@sasa.test')) {
            Auth::login($users['grievance@sasa.test']);
        }

        foreach ([
            [8, 'compensation_amount', 'Boundary of plot disputed after valuation', 'The PAP says the surveyed boundary excludes part of the field his family has farmed for twenty years, so the compensation is too low.'],
            [3, 'compensation_delay', 'Balance of compensation not yet received', 'Half of the compensation was paid in August. The PAP was told the balance would follow within a month and has heard nothing since.'],
            [5, 'compensation_delay', 'Payment on hold without explanation', 'The family was told payment is on hold but nobody explained why or what documents are needed to release it.'],
        ] as [$index, $subKey, $title, $description]) {
            $pap = $created[$index];

            $intake->intake($project, [
                'channel' => 'in_person',
                'received_at' => now()->subDays(20 - $index),
                'confidentiality' => 'normal',
                'complainant_name' => $pap->full_name,
                'complainant_phone' => $pap->phone,
                'complainant_type' => 'community_member',
                'stakeholder_id' => $pap->stakeholder_id,
                'location_id' => $pap->location_id,
                'location_text' => $pap->displayLocation(),
                'category_id' => $category?->id,
                'subcategory_id' => $categories[($category?->key ?? '').'.'.$subKey]->id ?? null,
                'severity' => 3,
                'title' => $title,
                'description' => $description,
                'desired_resolution' => 'Explain the position and pay what is owed.',
            ]);
        }

        Auth::logout();

        $this->command?->info(sprintf(
            'Seeded %d PAPs (with stakeholder records), their inventories, valuations and payments, and 3 consultations on %s.',
            count($created),
            $project->name,
        ));
    }
}
