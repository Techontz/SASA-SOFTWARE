<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform services: attachments, audit, notifications, saved views,
 * import/export, reporting, AI, voice and the offline sync ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('attachable_type', 60);
            $table->unsignedBigInteger('attachable_id');
            $table->uuid('client_uuid')->nullable();

            $table->string('kind', 30)->default('document');
            // photo|document|minutes|attendance|consent|evidence|audio|video|other
            $table->string('original_name');
            $table->string('disk', 30)->default('local');
            $table->string('path');            // never exposed to the client
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->nullable();
            $table->text('caption')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->string('scan_status', 20)->default('skipped'); // pending|clean|infected|failed|skipped
            $table->text('scan_result')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id'], 'att_attachable_idx');
            $table->index(['project_id', 'kind']);
            $table->unique(['project_id', 'client_uuid'], 'att_project_uuid_unique');
        });

        /*
         * Append-only. Nothing in SASA is hard-deleted; "delete" means archive
         * with an audit event. Audit rows survive record archival.
         */
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();      // denormalised, survives user archival
            $table->string('action', 80);                 // grievance.resolved, stakeholder.sensitive_view...
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_reference', 40)->nullable();
            $table->string('summary')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->boolean('is_sensitive_view')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('request_id', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'created_at']);
            $table->index(['entity_type', 'entity_id'], 'audit_entity_idx');
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['project_id', 'is_sensitive_view'], 'audit_sensitive_idx');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_key', 80);   // grievance.created, commitment.overdue...
            $table->string('name');
            $table->json('recipient_roles')->nullable();   // role keys
            $table->boolean('notify_owner')->default(true);
            $table->boolean('notify_assignee')->default(true);
            $table->json('channels');          // ["in_app","email"]  (sms/whatsapp ready)
            $table->json('conditions')->nullable(); // {"severity_gte":4}
            $table->string('template_subject')->nullable();
            $table->text('template_body')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'project_id', 'event_key'], 'notif_rule_scope_unique');
            $table->index(['project_id', 'is_active']);
        });

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 40);  // stakeholders|engagements|grievances|commitments|concerns
            $table->string('name');
            $table->json('filters');
            $table->json('columns')->nullable();
            $table->string('sort', 60)->nullable();
            $table->string('visibility', 20)->default('private'); // private|role|project
            $table->foreignId('shared_with_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['project_id', 'entity', 'visibility'], 'sv_project_entity_vis_idx');
            $table->index(['user_id', 'is_pinned']);
        });

        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 40);
            $table->string('original_name');
            $table->string('path');
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->string('status', 20)->default('uploaded');
            // uploaded|mapped|validated|committing|committed|failed|cancelled
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->unsignedInteger('rows_committed')->default(0);
            $table->unsignedInteger('last_committed_row')->default(0); // resumable batches
            $table->json('errors')->nullable();
            $table->json('preview')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'entity', 'status'], 'imp_project_entity_status_idx');
        });

        Schema::create('export_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity', 40);
            $table->string('format', 10);
            $table->json('filters')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->boolean('identity_stripped')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });

        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('template', 60); // executive_summary|grievance_register|sla_performance|...
            $table->json('sections')->nullable();
            $table->json('filters')->nullable();
            $table->json('default_period')->nullable();
            $table->json('formats')->nullable();
            $table->string('schedule', 30)->nullable(); // weekly|monthly|quarterly|null
            $table->json('recipients')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organisation_id', 'project_id', 'key'], 'repdef_scope_key_unique');
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 20);
            $table->string('name');
            $table->string('template', 60);
            $table->string('format', 10);
            $table->json('filters')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 20)->default('queued'); // queued|generating|ready|failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('metrics')->nullable();   // snapshot of the numbers as generated
            $table->text('failure_reason')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->index(['project_id', 'status', 'created_at'], 'rep_project_status_idx');
        });

        /*
         * AI proposals are stored SEPARATELY from confirmed human values, with
         * a confidence score. The case shows "AI-suggested — confirm" until a
         * human accepts or changes it. AI never owns findings or closure.
         */
        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->string('kind', 40); // category|subcategory|severity|summary|routing|language
            $table->json('suggestion');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->json('raw_response')->nullable();
            $table->string('status', 20)->default('pending'); // pending|accepted|modified|rejected
            $table->json('accepted_value')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'kind'], 'ai_subject_kind_idx');
            $table->index(['project_id', 'status']);
        });

        Schema::create('voice_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('external_id', 120);
            $table->text('caller_number')->nullable();      // encrypted
            $table->string('caller_number_hash', 64)->nullable();
            $table->string('language', 10)->nullable();
            $table->string('status', 30)->default('in_progress');
            // in_progress|consent_denied|completed|truncated|abandoned|failed|callback_queued
            $table->boolean('consent_granted')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->text('consent_statement')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('was_truncated')->default(false);
            $table->boolean('extension_offered')->default(false);
            $table->boolean('extension_accepted')->default(false);
            $table->boolean('needs_human_review')->default(false);
            $table->boolean('callback_requested')->default(false);
            $table->string('audio_path')->nullable();
            $table->longText('transcript')->nullable();
            $table->json('turns')->nullable();          // structured question/answer trail
            $table->json('ai_metadata')->nullable();
            $table->foreignId('grievance_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'needs_human_review'], 'voice_review_idx');
        });

        Schema::table('grievances', function (Blueprint $table) {
            $table->foreign('voice_call_id')->references('id')->on('voice_calls')->nullOnDelete();
        });

        /*
         * Offline sync ledger. Server-side record of every client operation,
         * keyed by a client-generated UUID so a retried batch is idempotent.
         */
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_uuid');
            $table->string('device_id', 64);
            $table->string('entity', 40);
            $table->string('operation', 20);  // create|update|attach
            $table->uuid('entity_uuid')->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('accepted'); // accepted|applied|rejected|conflict
            $table->unsignedBigInteger('server_entity_id')->nullable();
            $table->string('server_reference', 20)->nullable();
            $table->text('error')->nullable();
            $table->json('validation_errors')->nullable();
            $table->unsignedSmallInteger('client_retry_count')->default(0);
            $table->timestamp('client_created_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('operation_uuid');
            $table->index(['project_id', 'status']);
            $table->index(['user_id', 'device_id']);
        });

        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_operation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_reference', 40)->nullable();
            $table->json('conflicting_fields'); // {field: {local, server, server_changed_by, server_changed_at}}
            $table->string('device_id', 64)->nullable();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open'); // open|resolved|dismissed
            $table->string('resolution', 20)->nullable();  // keep_local|keep_server|merged
            $table->json('resolved_values')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['entity', 'entity_id'], 'conflict_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_operations');
        Schema::table('grievances', function (Blueprint $table) {
            $table->dropForeign(['voice_call_id']);
        });
        Schema::dropIfExists('voice_calls');
        Schema::dropIfExists('ai_suggestions');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('report_definitions');
        Schema::dropIfExists('export_logs');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('saved_views');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('attachments');
    }
};
