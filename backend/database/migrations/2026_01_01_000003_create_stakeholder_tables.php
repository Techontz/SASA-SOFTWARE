<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE 1 — Stakeholder Register.
 *
 * A living register, not a spreadsheet. Priority is derived from a versioned
 * assessment, always shown next to the stored value, and overridable with a
 * reason that lands in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stakeholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);            // STK-0001 — immutable
            $table->uuid('client_uuid')->nullable();    // offline idempotency key

            $table->string('name');
            $table->string('alias')->nullable();
            $table->string('type', 40);                 // individual, household, community_group, cso...
            $table->string('sub_type', 60)->nullable();
            $table->string('organisation_name')->nullable();
            $table->string('position')->nullable();

            // --- Contact ---------------------------------------------------
            $table->string('phone', 40)->nullable();
            $table->string('alternate_phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('preferred_language', 10)->nullable();
            $table->string('preferred_contact_method', 30)->nullable();
            $table->text('physical_address')->nullable();
            $table->string('postal_address')->nullable();

            // --- Location --------------------------------------------------
            $table->foreignId('primary_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('country', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('district')->nullable();
            $table->string('ward')->nullable();
            $table->string('village')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // --- Assessment (denormalised current values, see assessments) ---
            $table->string('influence', 10)->nullable();  // high | medium | low
            $table->string('interest', 10)->nullable();
            $table->string('power', 10)->nullable();
            $table->string('impact', 10)->nullable();
            $table->unsignedSmallInteger('priority_score')->nullable();
            $table->string('calculated_priority', 10)->nullable();
            $table->string('priority', 10)->nullable();     // stored (may be overridden)
            $table->boolean('priority_overridden')->default(false);
            $table->string('engagement_strategy')->nullable();
            $table->string('communication_frequency', 30)->nullable();

            // --- Substance -------------------------------------------------
            $table->text('concerns_expectations')->nullable();
            $table->text('notes')->nullable();

            // --- Vulnerability / consent ------------------------------------
            $table->boolean('is_vulnerable')->default(false);
            $table->json('vulnerability_categories')->nullable();
            $table->boolean('is_indigenous_or_minority')->default(false);
            $table->string('consent_status', 30)->default('not_recorded'); // granted | refused | not_recorded | withdrawn
            $table->string('consent_basis', 60)->nullable();               // consent | legitimate_interest | legal_obligation
            $table->date('consent_date')->nullable();
            $table->string('identification_source', 60)->nullable();       // survey, census, meeting, self-identified
            $table->string('identification_method', 60)->nullable();

            /*
             * Optional disaggregation dimensions. Bounded typed JSON, never an
             * EAV store, never mandatory. Sensitive dimensions are collected
             * only where configuration enables them.
             */
            $table->json('demographics')->nullable();
            $table->json('custom_fields')->nullable();

            // --- Register governance ---------------------------------------
            $table->string('status', 20)->default('active'); // active | inactive | merged | archived
            $table->foreignId('merged_into_id')->nullable()->constrained('stakeholders')->nullOnDelete();
            $table->date('review_date')->nullable();
            $table->date('last_engaged_on')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('duplicate_hash', 64)->nullable(); // normalised name+phone+village

            $table->timestamp('captured_at')->nullable(); // when the field officer entered it
            $table->timestamp('synced_at')->nullable();   // when it reached the server
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->unique(['project_id', 'client_uuid']);
            $table->index(['project_id', 'status', 'priority'], 'stk_project_status_priority_idx');
            $table->index(['project_id', 'type']);
            $table->index(['project_id', 'is_vulnerable']);
            $table->index(['project_id', 'review_date']);
            $table->index(['organisation_id', 'created_at']);
            $table->index('duplicate_hash');
            $table->index('owner_id');
            $table->fullText(['name', 'alias', 'organisation_name', 'concerns_expectations'], 'stk_fulltext');
        });

        Schema::create('stakeholder_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stakeholder_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('preferred_language', 10)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['stakeholder_id', 'is_primary']);
        });

        Schema::create('stakeholder_location', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stakeholder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('relationship', 30)->default('resides'); // resides | operates | affected
            $table->timestamps();

            $table->unique(['stakeholder_id', 'location_id', 'relationship'], 'stk_loc_unique');
        });

        /*
         * Versioned assessment. Every recalculation or override writes a new
         * row; the current row is flagged. This is what makes "the calculated
         * value is always shown next to the stored value" possible over time.
         */
        Schema::create('stakeholder_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stakeholder_id')->constrained()->cascadeOnDelete();
            $table->string('influence', 10)->nullable();
            $table->string('interest', 10)->nullable();
            $table->string('power', 10)->nullable();
            $table->string('impact', 10)->nullable();
            $table->json('weights');                      // snapshot of the weights used
            $table->unsignedSmallInteger('score');
            $table->string('calculated_priority', 10);
            $table->string('stored_priority', 10);
            $table->boolean('is_override')->default(false);
            $table->string('previous_priority', 10)->nullable();
            $table->text('override_reason')->nullable();
            $table->boolean('is_current')->default(true);
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at');
            $table->timestamps();

            $table->index(['stakeholder_id', 'is_current']);
            $table->index(['project_id', 'is_override']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stakeholder_assessments');
        Schema::dropIfExists('stakeholder_location');
        Schema::dropIfExists('stakeholder_contacts');
        Schema::dropIfExists('stakeholders');
    }
};
