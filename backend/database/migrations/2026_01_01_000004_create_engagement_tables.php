<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE 2 — Stakeholder Management.
 *
 * PLAN-0001 -> ENG-0001 -> concerns + commitments, with a planned-vs-actual
 * flag on every logged engagement. Two exits: resolved inside engagement, or
 * escalated into Module 3 as GRV-0001.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);            // PLAN-0001
            $table->uuid('client_uuid')->nullable();

            $table->string('title');
            $table->string('project_phase', 60)->nullable();
            $table->foreignId('stakeholder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stakeholder_group')->nullable(); // when the plan targets a group, not a record
            $table->text('purpose')->nullable();
            $table->string('method', 60)->nullable();        // meeting, focus group, radio, door-to-door...
            $table->date('target_date')->nullable();
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_text')->nullable();

            $table->boolean('vulnerable_group_accommodation')->default(false);
            $table->text('accommodation_notes')->nullable();
            $table->boolean('fpic_required')->default(false);
            $table->text('fpic_notes')->nullable();
            $table->boolean('grievance_channel_available')->default(true);

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_team', 60)->nullable();
            $table->string('priority', 10)->default('medium');
            $table->string('recurrence', 20)->default('once'); // once|weekly|monthly|quarterly|semi_annual|annual
            $table->date('recurrence_until')->nullable();
            $table->foreignId('recurrence_parent_id')->nullable()->constrained('engagement_plans')->nullOnDelete();

            $table->string('status', 20)->default('planned'); // planned|completed|rescheduled|postponed|cancelled|missed
            $table->text('status_reason')->nullable();
            $table->decimal('budget_amount', 14, 2)->nullable();
            $table->string('budget_currency', 3)->nullable();
            $table->text('resources_required')->nullable();
            $table->json('custom_fields')->nullable();

            $table->timestamp('captured_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->unique(['project_id', 'client_uuid']);
            $table->index(['project_id', 'status', 'target_date'], 'plan_project_status_date_idx');
            $table->index(['project_id', 'owner_id']);
            $table->index('stakeholder_id');
        });

        Schema::create('engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);            // ENG-0001
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('engagement_plan_id')->nullable()->constrained()->nullOnDelete();

            $table->string('topic');
            $table->string('project_phase', 60)->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_text')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->dateTime('held_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('method', 60)->nullable();
            $table->string('venue')->nullable();
            $table->string('organised_by')->nullable();
            $table->foreignId('facilitator_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('aim')->nullable();
            $table->longText('discussion_points')->nullable();
            $table->longText('outcomes')->nullable();

            // --- Attendance -------------------------------------------------
            $table->unsignedInteger('attendance_total')->default(0);
            $table->unsignedInteger('attendance_female')->default(0);
            $table->unsignedInteger('attendance_male')->default(0);
            $table->unsignedInteger('attendance_youth')->default(0);
            $table->unsignedInteger('attendance_elderly')->default(0);
            $table->unsignedInteger('attendance_disability')->default(0);
            $table->unsignedInteger('attendance_vulnerable')->default(0);
            $table->json('attendance_breakdown')->nullable(); // configurable extra categories
            $table->boolean('vulnerable_groups_present')->default(false);
            $table->json('vulnerable_groups')->nullable();

            /*
             * Planned-vs-actual. Computed by the domain service, stored so the
             * dashboards do not have to recompute across the whole table.
             */
            $table->string('planned_vs_actual', 20)->default('unplanned'); // on_plan|late|early|unplanned|missed|rescheduled|cancelled
            $table->integer('variance_days')->nullable();

            $table->string('status', 20)->default('logged'); // draft|logged|verified|archived
            $table->json('custom_fields')->nullable();

            $table->timestamp('captured_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->unique(['project_id', 'client_uuid']);
            $table->index(['project_id', 'held_at']);
            $table->index(['project_id', 'planned_vs_actual'], 'eng_project_pva_idx');
            $table->index(['project_id', 'method']);
            $table->index('engagement_plan_id');
            $table->fullText(['topic', 'discussion_points', 'outcomes'], 'eng_fulltext');
        });

        Schema::create('engagement_stakeholder', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stakeholder_id')->constrained()->cascadeOnDelete();
            $table->boolean('attended')->default(true);
            $table->timestamps();

            $table->unique(['engagement_id', 'stakeholder_id']);
        });

        Schema::create('engagement_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stakeholder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('category', 40)->nullable();  // community, government, contractor, staff, cso...
            $table->string('organisation_name')->nullable();
            $table->string('position')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_vulnerable')->default(false);
            $table->json('demographics')->nullable();    // optional disaggregation dimensions
            $table->boolean('signed_attendance')->default(false);
            $table->timestamps();

            $table->index(['engagement_id', 'category']);
            $table->index('stakeholder_id');
        });

        Schema::create('concerns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);            // CON-0001
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('engagement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stakeholder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grievance_category_id')->nullable(); // FK added in grievance migration

            $table->string('title');
            $table->longText('description');
            $table->string('raised_by')->nullable();
            $table->date('raised_on');
            $table->string('severity_hint', 10)->nullable();
            $table->string('status', 20)->default('open'); // open|addressed|escalated|closed
            $table->text('response')->nullable();
            $table->foreignId('grievance_id')->nullable(); // FK added in grievance migration
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('captured_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->unique(['project_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'raised_on']);
            $table->index('engagement_id');
            $table->index('stakeholder_id');
            $table->fullText(['title', 'description'], 'concern_fulltext');
        });

        Schema::create('commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);            // COM-0001
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('engagement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('concern_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grievance_id')->nullable(); // FK added in grievance migration
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->longText('commitment_text');
            $table->string('source_type', 30)->default('engagement'); // engagement|grievance|concern|manual
            $table->date('source_date')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_team', 60)->nullable();
            $table->date('due_date')->nullable();
            $table->string('priority', 10)->default('medium');
            $table->string('risk_level', 10)->default('medium'); // low|medium|high
            $table->string('status', 20)->default('open');       // open|in_progress|overdue|fulfilled|cancelled
            $table->date('completed_on')->nullable();
            $table->text('evidence_notes')->nullable();
            $table->string('verification_status', 20)->default('unverified'); // unverified|verified|disputed
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('reminders_sent')->nullable();

            $table->timestamp('captured_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->unique(['project_id', 'client_uuid']);
            $table->index(['project_id', 'status', 'due_date'], 'com_project_status_due_idx');
            $table->index(['project_id', 'risk_level']);
            $table->index(['project_id', 'verification_status'], 'com_project_verification_idx');
            $table->index('owner_id');
            $table->fullText(['commitment_text'], 'com_fulltext');
        });

        Schema::create('commitment_stakeholder', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commitment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stakeholder_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['commitment_id', 'stakeholder_id'], 'com_stk_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commitment_stakeholder');
        Schema::dropIfExists('commitments');
        Schema::dropIfExists('concerns');
        Schema::dropIfExists('engagement_participants');
        Schema::dropIfExists('engagement_stakeholder');
        Schema::dropIfExists('engagements');
        Schema::dropIfExists('engagement_plans');
    }
};
