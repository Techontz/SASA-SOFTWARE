<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA engine. The engine holds the mechanism; the client sets the numbers.
 * Nothing is hard-coded to "7 working days".
 *
 * Policy resolution is most-specific-wins:
 *   project + category + severity  >  project + category  >  project + severity
 *   >  project  >  organisation default
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('country', 2)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('grievance_categories')->cascadeOnDelete();
            $table->unsignedTinyInteger('severity')->nullable();
            $table->string('clock', 30);   // acknowledgement|investigation|resolution|contact|commitment
            $table->string('unit', 20);    // working_days|working_hours|calendar_days|calendar_hours
            $table->unsignedSmallInteger('target_value');
            $table->foreignId('working_calendar_id')->nullable()->constrained()->nullOnDelete();
            $table->json('reminder_thresholds')->nullable(); // [50, 80]
            $table->foreignId('escalate_to_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('specificity')->default(0); // precomputed match score
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'clock', 'is_active'], 'sla_pol_project_clock_idx');
            $table->index(['organisation_id', 'clock']);
        });

        Schema::create('sla_clocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 60);   // grievance | commitment
            $table->unsignedBigInteger('subject_id');
            $table->string('clock', 30);
            $table->unsignedTinyInteger('cycle')->default(1);
            $table->foreignId('sla_policy_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('due_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('breached_at')->nullable();
            $table->string('state', 20)->default('running'); // running|at_risk|breached|met|met_late|paused|cancelled

            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->unsignedInteger('paused_seconds_total')->default(0);
            $table->json('pause_history')->nullable();

            $table->json('reminders_sent')->nullable(); // [50, 80]
            $table->unsignedSmallInteger('target_value')->nullable();
            $table->string('unit', 20)->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'clock', 'cycle'], 'sla_clock_subject_unique');
            $table->index(['project_id', 'state', 'due_at'], 'sla_clock_project_state_idx');
            $table->index(['project_id', 'clock']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_clocks');
        Schema::dropIfExists('sla_policies');
    }
};
