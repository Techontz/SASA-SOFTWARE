<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ESHS contractor assurance: anomalies raised by the client or supervision
 * team against a contractor, answered by the contractor with closure evidence,
 * and closed only by the supervision team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eshs_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);              // ESA-0001
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->date('raised_on');
            $table->string('contractor', 160);
            $table->string('subcontractor', 160)->nullable();
            $table->string('category', 40);               // environment|safety|health|social
            $table->string('subcategory', 160);
            $table->longText('description');
            $table->string('anomaly_type', 80);
            $table->string('risk_rating', 10);            // low|medium|high|critical
            $table->string('root_cause_group', 80)->nullable();
            $table->string('root_cause', 160)->nullable();

            // The action belongs to the contractor.
            $table->text('contractor_action')->nullable();
            $table->string('action_owner_name', 160)->nullable();
            $table->string('action_owner_email', 255)->nullable();
            $table->string('action_owner_phone', 40)->nullable();
            $table->date('target_date')->nullable();
            $table->text('progress_notes')->nullable();

            $table->string('status', 20)->default('open'); // open|in_progress|closed
            $table->timestamp('evidence_submitted_at')->nullable();
            $table->foreignId('evidence_submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closure_notes')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->date('closed_on')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->index(['project_id', 'status', 'target_date'], 'esa_project_status_target_idx');
            $table->index(['project_id', 'contractor'], 'esa_project_contractor_idx');
            $table->index(['project_id', 'category'], 'esa_project_category_idx');
            $table->index(['project_id', 'risk_rating'], 'esa_project_risk_idx');
        });

        // A contractor's people see only their own company's anomalies.
        Schema::table('project_memberships', function (Blueprint $table) {
            $table->string('contractor_name', 160)->nullable()->after('handling_groups');
        });
    }

    public function down(): void
    {
        Schema::table('project_memberships', function (Blueprint $table) {
            $table->dropColumn('contractor_name');
        });

        Schema::dropIfExists('eshs_anomalies');
    }
};
