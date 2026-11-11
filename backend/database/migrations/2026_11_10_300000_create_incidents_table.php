<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INC-0001 — the Incident Notification Report. Submitted from the field,
 * reviewed and closed by someone holding incident.manage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);            // INC-0001
            $table->uuid('client_uuid')->nullable();

            $table->dateTime('occurred_at');
            $table->string('company', 160)->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_detail')->nullable();

            $table->string('category', 40);
            $table->string('incident_type', 60);
            $table->unsignedTinyInteger('actual_consequence');
            $table->unsignedTinyInteger('potential_consequence');
            $table->longText('summary');

            $table->foreignId('reported_by')->constrained('users');
            $table->string('contractor_manager', 160)->nullable();
            $table->string('resident_engineer', 160)->nullable();

            $table->string('status', 20)->default('submitted'); // submitted|under_review|closed|archived
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->unique(['project_id', 'client_uuid']);
            $table->index(['project_id', 'status', 'occurred_at'], 'inc_project_status_date_idx');
            $table->index(['project_id', 'category']);
            $table->index(['project_id', 'potential_consequence'], 'inc_project_potential_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
