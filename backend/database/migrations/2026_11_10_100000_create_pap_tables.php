<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAP Master Register — the resettlement action plan's register of Project
 * Affected Persons and everything owed to them: land, assets, crops,
 * valuation, entitlements, payments, relocation and livelihood restoration.
 *
 * Every child table carries a plain `status` column (active | archived) for
 * the Archivable trait; the business status of each record has its own
 * specifically named column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);                 // PAP-0001
            $table->uuid('client_uuid')->nullable();
            $table->string('household_id', 40)->nullable();   // HH-0001, shared by members of one household
            $table->foreignId('stakeholder_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('region', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('ward', 120)->nullable();
            $table->string('village', 120)->nullable();
            $table->string('kitongoji', 120)->nullable();

            $table->string('full_name');
            $table->text('national_id')->nullable();          // encrypted at rest
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->unsignedTinyInteger('stated_age')->nullable(); // only when no date of birth is known
            $table->string('marital_status', 30)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('alternative_contact', 120)->nullable();
            $table->string('physical_address', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('next_of_kin', 160)->nullable();
            $table->string('next_of_kin_contact', 120)->nullable();
            $table->string('vulnerability_category', 60)->default('none');
            $table->text('remarks')->nullable();

            $table->string('status', 20)->default('active');
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->unique(['project_id', 'client_uuid']);
            $table->index(['project_id', 'household_id']);
            $table->index(['project_id', 'village']);
            $table->index(['project_id', 'archived_at']);
        });

        $child = function (Blueprint $table, bool $referenced = true) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pap_id')->constrained('paps')->cascadeOnDelete();
            if ($referenced) {
                $table->string('reference', 20);
            }
        };

        $tail = function (Blueprint $table, string $prefix, bool $referenced = true) {
            $table->string('status', 20)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            if ($referenced) {
                $table->unique(['project_id', 'reference'], $prefix.'_reference_unique');
            }
            $table->index(['pap_id', 'archived_at'], $prefix.'_pap_idx');
        };

        // Part B
        Schema::create('pap_household_surveys', function (Blueprint $table) use ($child, $tail) {
            $child($table, false);
            $table->string('household_id', 40)->nullable();
            $table->date('survey_date')->nullable();
            $table->string('household_head', 160)->nullable();
            $table->unsignedSmallInteger('household_size')->nullable();
            $table->unsignedSmallInteger('number_of_adults')->nullable();
            $table->unsignedSmallInteger('number_of_children')->nullable();
            $table->string('education_level', 40)->nullable();
            $table->string('primary_occupation', 120)->nullable();
            $table->string('secondary_occupation', 120)->nullable();
            $table->decimal('monthly_household_income', 15, 2)->nullable();
            $table->string('main_income_source', 120)->nullable();
            $table->decimal('asset_ownership_score', 6, 2)->nullable();
            $table->string('poverty_category', 40)->nullable();
            $table->text('livestock_owned')->nullable();
            $table->string('access_to_electricity', 40)->nullable();
            $table->string('access_to_water', 40)->nullable();
            $table->string('access_to_sanitation', 40)->nullable();
            $table->text('comments')->nullable();
            $tail($table, 'pap_hhs', false);
        });

        // Part C
        Schema::create('pap_land_parcels', function (Blueprint $table) use ($child, $tail) {
            $child($table);
            $table->string('plot_number', 60)->nullable();
            $table->string('village', 120)->nullable();
            $table->string('land_use', 40)->nullable();
            $table->string('ownership_type', 40)->nullable();
            $table->string('ccro_number', 60)->nullable();
            $table->string('title_number', 60)->nullable();
            $table->decimal('total_plot_size_ha', 12, 4)->nullable();
            $table->decimal('affected_area_ha', 12, 4)->nullable();
            $table->decimal('percentage_affected', 6, 2)->nullable();   // derived
            $table->decimal('gps_easting', 12, 3)->nullable();
            $table->decimal('gps_northing', 12, 3)->nullable();
            $table->string('map_reference', 120)->nullable();
            $table->string('land_status', 40)->nullable();
            $table->text('remarks')->nullable();
            $tail($table, 'pap_land');
        });

        // Part D
        Schema::create('pap_assets', function (Blueprint $table) use ($child, $tail) {
            $child($table);
            $table->string('asset_category', 40);
            $table->string('asset_type', 120)->nullable();
            $table->text('description')->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('area', 12, 2)->nullable();          // derived from length × width when both are given
            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('condition', 30)->nullable();
            $table->string('affected_status', 30)->nullable();
            $table->string('photo_reference', 160)->nullable();
            $table->text('remarks')->nullable();
            $tail($table, 'pap_asset');
        });

        // Part E
        Schema::create('pap_crops', function (Blueprint $table) use ($child, $tail) {
            $child($table);
            $table->string('plot_number', 60)->nullable();
            $table->string('crop_type', 120)->nullable();
            $table->unsignedInteger('number_of_plants')->nullable();
            $table->decimal('area_cultivated', 12, 4)->nullable();  // hectares
            $table->string('tree_species', 120)->nullable();
            $table->unsignedSmallInteger('tree_age')->nullable();    // years
            $table->string('productive_status', 30)->nullable();
            $table->string('rate_basis', 20)->default('per_plant');
            $table->decimal('unit_rate', 15, 2)->nullable();
            $table->decimal('compensation_amount', 15, 2)->nullable(); // derived
            $table->text('remarks')->nullable();
            $tail($table, 'pap_crop');
        });

        // Part F
        Schema::create('pap_valuations', function (Blueprint $table) use ($child, $tail) {
            $child($table);
            $table->date('valuation_date')->nullable();
            $table->string('valuer_name', 160)->nullable();
            $table->string('valuation_report_number', 80)->nullable();
            foreach ([
                'land_value', 'structure_value', 'crop_value', 'tree_value', 'business_loss',
                'disturbance_allowance', 'transport_allowance', 'accommodation_allowance',
                'loss_of_profit_allowance', 'other_allowances',
            ] as $component) {
                $table->decimal($component, 15, 2)->default(0);
            }
            $table->decimal('total_compensation_value', 15, 2)->default(0); // derived
            $table->string('approval_status', 30)->default('draft');
            $table->date('approval_date')->nullable();
            $tail($table, 'pap_val');
        });

        // Part G
        Schema::create('pap_entitlements', function (Blueprint $table) use ($child, $tail) {
            $child($table);
            $table->string('entitlement_type', 40);
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('unit_rate', 15, 2)->nullable();
            $table->decimal('value', 15, 2)->nullable();          // derived
            $table->date('approval_date')->nullable();
            $table->string('entitlement_status', 30)->default('proposed');
            $table->text('remarks')->nullable();
            $tail($table, 'pap_ent');
        });

        // Part H
        Schema::create('pap_payments', function (Blueprint $table) use ($child, $tail) {
            $child($table);
            $table->string('compensation_agreement_number', 80)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->text('bank_account_number')->nullable();   // encrypted at rest
            $table->text('mobile_money_number')->nullable();   // encrypted at rest
            $table->decimal('amount_approved', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('outstanding_amount', 15, 2)->default(0); // derived
            $table->date('payment_date')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('receipt_number', 80)->nullable();
            $table->boolean('acknowledgement_signed')->default(false);
            $table->string('witness_name', 160)->nullable();
            $table->string('payment_status', 30)->default('pending');
            $table->text('comments')->nullable();
            $tail($table, 'pap_pay');
            $table->index(['project_id', 'payment_status']);
        });

        // Part I
        Schema::create('pap_relocations', function (Blueprint $table) use ($child, $tail) {
            $child($table);
            $table->boolean('relocation_required')->default(true);
            $table->string('replacement_plot_number', 60)->nullable();
            $table->string('replacement_house_number', 60)->nullable();
            $table->date('house_handover_date')->nullable();
            $table->date('moving_date')->nullable();
            $table->boolean('moving_assistance_provided')->default(false);
            $table->boolean('transit_assistance_provided')->default(false);
            $table->string('relocation_status', 40)->default('planned');
            $table->text('remarks')->nullable();
            $tail($table, 'pap_rel');
        });

        // Part J
        Schema::create('pap_livelihoods', function (Blueprint $table) use ($child, $tail) {
            $child($table);
            $table->string('income_source_lost', 160)->nullable();
            $table->string('livelihood_program', 160)->nullable();
            $table->text('training_provided')->nullable();
            $table->decimal('grant_amount', 15, 2)->nullable();
            $table->text('business_startup_support')->nullable();
            $table->text('agricultural_support')->nullable();
            $table->date('date_enrolled')->nullable();
            $table->date('date_completed')->nullable();
            $table->json('monitoring_visits')->nullable();   // [{visit_date, visited_by, findings}]
            $table->string('income_restored', 20)->nullable();
            $table->string('programme_status', 30)->default('not_started');
            $tail($table, 'pap_lrp');
        });

        // Part M
        Schema::create('pap_documents', function (Blueprint $table) use ($child, $tail) {
            $child($table);
            $table->string('document_type', 40);
            $table->string('document_number', 80)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('digital_file_name', 255)->nullable();
            $table->string('storage_location', 255)->nullable();
            $table->string('verified_by', 160)->nullable();
            $table->date('verification_date')->nullable();
            $tail($table, 'pap_doc');
        });

        // Part L — project level, no PAP
        Schema::create('pap_consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);
            $table->date('consultation_date');
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('village', 120)->nullable();
            $table->string('meeting_type', 40)->nullable();
            $table->text('topic_discussed');
            $table->string('facilitator', 160)->nullable();
            $table->unsignedInteger('number_male')->default(0);
            $table->unsignedInteger('number_female')->default(0);
            $table->unsignedInteger('number_vulnerable')->default(0);
            $table->string('attendance_sheet_reference', 120)->nullable();
            $table->text('key_concerns_raised')->nullable();
            $table->text('actions_agreed')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->index(['project_id', 'consultation_date']);
        });
    }

    public function down(): void
    {
        foreach ([
            'pap_consultations', 'pap_documents', 'pap_livelihoods', 'pap_relocations', 'pap_payments',
            'pap_entitlements', 'pap_valuations', 'pap_crops', 'pap_assets', 'pap_land_parcels',
            'pap_household_surveys', 'paps',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
