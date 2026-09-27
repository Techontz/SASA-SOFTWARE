<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE 3 — Grievance Management.
 *
 * Every channel converges on ONE case record. Complainant identity fields are
 * encrypted at rest and carry blind-index hashes so exact-match search still
 * works without decrypting the table. An anonymous case stores no identity at
 * all — there is nothing to leak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('grievance_categories')->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('default_severity')->nullable();
            /*
             * Restricted categories (SEA/SH, Retaliation, Ethics & Compliance)
             * are visible only to the named handling groups, excluded from
             * general exports and reported in aggregate only. Configuration —
             * not hard-coded behaviour.
             */
            $table->boolean('is_restricted')->default(false);
            $table->json('handling_groups')->nullable();
            $table->boolean('aggregate_reporting_only')->default(false);
            $table->timestamp('retired_at')->nullable(); // hidden from new cases, kept on historical ones
            $table->timestamps();

            $table->unique(['organisation_id', 'project_id', 'key'], 'grv_cat_scope_key_unique');
            $table->index(['project_id', 'parent_id']);
        });

        Schema::create('grievances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);            // GRV-0001
            $table->uuid('client_uuid')->nullable();
            $table->string('idempotency_key', 100)->nullable(); // channel-level replay protection

            // --- Intake ------------------------------------------------------
            $table->string('channel', 30);              // voice|whatsapp|sms|web|in_person|email|leader|suggestion_box
            $table->string('channel_reference')->nullable();
            $table->dateTime('received_at');
            $table->dateTime('occurred_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            // --- Confidentiality --------------------------------------------
            $table->string('confidentiality', 20)->default('normal'); // normal|confidential|anonymous
            $table->boolean('is_restricted')->default(false);         // inherited from category
            $table->json('handling_groups')->nullable();

            // --- Complainant (encrypted; absent entirely for anonymous cases) -
            $table->string('complainant_type', 40)->nullable(); // community_member|worker|contractor|leader...
            $table->text('complainant_name')->nullable();
            $table->text('complainant_phone')->nullable();
            $table->text('complainant_email')->nullable();
            $table->text('complainant_address')->nullable();
            $table->string('complainant_name_hash', 64)->nullable();
            $table->string('complainant_phone_hash', 64)->nullable();
            $table->string('complainant_language', 10)->nullable();
            $table->string('preferred_contact_method', 30)->nullable();
            $table->foreignId('stakeholder_id')->nullable()->constrained()->nullOnDelete();

            // --- Location (precise location is a sensitive field) -------------
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_text')->nullable();
            $table->text('precise_location')->nullable(); // encrypted
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // --- Classification ----------------------------------------------
            $table->foreignId('category_id')->nullable()->constrained('grievance_categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('grievance_categories')->nullOnDelete();
            $table->unsignedTinyInteger('severity')->nullable(); // 1..5
            $table->boolean('classification_confirmed')->default(false);
            $table->foreignId('classified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('classified_at')->nullable();

            // --- Substance -----------------------------------------------------
            $table->string('title');
            $table->longText('description');
            $table->longText('desired_resolution')->nullable();
            $table->json('demographics')->nullable();
            $table->json('custom_fields')->nullable();

            // --- Lifecycle ------------------------------------------------------
            $table->string('status', 30)->default('new');
            // new|classified|assigned|acknowledged|under_investigation|action_pending
            // |resolved|awaiting_confirmation|closed|reopened|rejected|withdrawn
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_team', 60)->nullable();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_method', 30)->nullable();
            // Where a channel cannot carry an acknowledgement we record that,
            // rather than leaving the field blank and silently degrading stats.
            $table->boolean('acknowledgement_possible')->default(true);
            $table->string('acknowledgement_not_possible_reason')->nullable();

            $table->timestamp('investigation_started_at')->nullable();
            $table->longText('investigation_summary')->nullable();
            $table->longText('investigation_findings')->nullable();
            $table->timestamp('investigation_completed_at')->nullable();

            $table->longText('corrective_action')->nullable();
            $table->foreignId('corrective_action_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('corrective_action_due')->nullable();

            $table->longText('resolution_summary')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('complainant_response', 20)->nullable(); // accepted|rejected|no_response|not_contactable
            $table->timestamp('complainant_responded_at')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closure_notes')->nullable();

            // --- Reopen (blueprint §10.3 — the source status list has none) -----
            $table->unsignedTinyInteger('resolution_cycle')->default(1);
            $table->unsignedTinyInteger('reopen_count')->default(0);
            $table->timestamp('last_reopened_at')->nullable();

            // --- Escalation ------------------------------------------------------
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('escalated_at')->nullable();

            // --- Denormalised SLA state (authoritative rows live in sla_clocks) --
            $table->timestamp('acknowledgement_due_at')->nullable();
            $table->string('acknowledgement_sla_state', 20)->nullable(); // on_time|at_risk|breached|paused|met
            $table->timestamp('resolution_due_at')->nullable();
            $table->string('resolution_sla_state', 20)->nullable();

            // --- Provenance --------------------------------------------------------
            $table->foreignId('source_concern_id')->nullable()->constrained('concerns')->nullOnDelete();
            $table->foreignId('voice_call_id')->nullable();
            $table->boolean('has_ai_suggestions')->default(false);

            $table->timestamp('captured_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->unique(['project_id', 'client_uuid']);
            $table->unique(['project_id', 'idempotency_key'], 'grv_project_idem_unique');
            $table->index(['project_id', 'status', 'severity'], 'grv_project_status_sev_idx');
            $table->index(['project_id', 'received_at']);
            $table->index(['project_id', 'category_id']);
            $table->index(['project_id', 'channel']);
            $table->index(['project_id', 'assigned_to_id'], 'grv_project_assignee_idx');
            $table->index(['project_id', 'confidentiality']);
            $table->index(['project_id', 'resolution_sla_state'], 'grv_project_res_sla_idx');
            $table->index(['project_id', 'location_id']);
            $table->index('complainant_phone_hash');
            $table->index('stakeholder_id');
            $table->fullText(['title', 'description', 'desired_resolution'], 'grv_fulltext');
        });

        // Deferred foreign keys from the Module 2 tables.
        Schema::table('concerns', function (Blueprint $table) {
            $table->foreign('grievance_category_id')->references('id')->on('grievance_categories')->nullOnDelete();
            $table->foreign('grievance_id')->references('id')->on('grievances')->nullOnDelete();
            $table->index('grievance_category_id');
            $table->index('grievance_id');
        });

        Schema::table('commitments', function (Blueprint $table) {
            $table->foreign('grievance_id')->references('id')->on('grievances')->nullOnDelete();
            $table->index('grievance_id');
        });

        /*
         * One row per resolution cycle. Reopening keeps the original case ID
         * and opens cycle 2 — without this, dissatisfaction produces duplicate
         * cases and the resolution statistics flatter the project.
         */
        Schema::create('grievance_resolution_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grievance_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('cycle_number');
            $table->timestamp('opened_at');
            $table->text('reopen_reason')->nullable();
            $table->longText('resolution_summary')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('complainant_response', 20)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['grievance_id', 'cycle_number']);
        });

        Schema::create('grievance_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grievance_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('note'); // note|investigation_step|contact|site_visit|decision
            $table->longText('body');
            $table->boolean('is_sensitive')->default(false);
            $table->date('occurred_on')->nullable();
            $table->unsignedTinyInteger('resolution_cycle')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();

            $table->index(['grievance_id', 'created_at']);
            $table->unique(['project_id', 'client_uuid'], 'gfu_project_uuid_unique');
        });

        // Assignment HISTORY, not a single field.
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grievance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_team', 60)->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->text('reason')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(['grievance_id', 'is_current']);
            $table->index(['project_id', 'assigned_to_id'], 'asg_project_assignee_idx');
        });

        Schema::create('grievance_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grievance_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('from_level')->default(0);
            $table->unsignedTinyInteger('to_level');
            $table->string('trigger', 40); // sla_breach|severity|manual|reopen|no_response
            $table->text('reason')->nullable();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('escalated_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('escalated_to_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->timestamp('escalated_at');
            $table->timestamps();

            $table->index(['project_id', 'escalated_at']);
            $table->index('grievance_id');
        });

        /*
         * Outbound (and inbound) complainant messaging. Always templated,
         * always logged against the case, so "we told them" is provable.
         */
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grievance_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('direction', 10); // outbound|inbound
            $table->string('channel', 30);
            $table->string('template_key', 80)->nullable();
            $table->text('recipient')->nullable(); // encrypted
            $table->string('subject')->nullable();
            $table->longText('body');
            $table->string('language', 10)->default('en');
            $table->string('status', 20)->default('queued'); // queued|sent|delivered|failed|not_possible
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['grievance_id', 'created_at']);
            $table->index(['project_id', 'channel', 'status'], 'comm_project_channel_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
        Schema::dropIfExists('grievance_escalations');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('grievance_follow_ups');
        Schema::dropIfExists('grievance_resolution_cycles');

        Schema::table('commitments', function (Blueprint $table) {
            $table->dropForeign(['grievance_id']);
        });
        Schema::table('concerns', function (Blueprint $table) {
            $table->dropForeign(['grievance_category_id']);
            $table->dropForeign(['grievance_id']);
        });

        Schema::dropIfExists('grievances');
        Schema::dropIfExists('grievance_categories');
    }
};
