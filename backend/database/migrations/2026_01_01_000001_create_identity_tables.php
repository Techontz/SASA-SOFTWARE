<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy + identity spine.
 *
 * ORGANISATION (hard tenant boundary) -> PROJECTS -> MEMBERSHIPS -> DATA.
 * Every operational table below this one carries organisation_id + project_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('country', 2)->nullable();
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 9)->nullable();
            $table->string('status', 20)->default('active'); // active | suspended | archived
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->text('description')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('sector', 60)->nullable(); // transmission, mining, roads...
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->string('status', 20)->default('active'); // active | on_hold | closed | archived
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->string('logo_path')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'code']);
            $table->index(['organisation_id', 'status']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 40)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('job_title')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('avatar_path')->nullable();
            $table->boolean('is_system_admin')->default(false);
            $table->boolean('mfa_enabled')->default(false);
            $table->text('mfa_secret')->nullable();
            $table->json('mfa_recovery_codes')->nullable();
            $table->string('status', 20)->default('active'); // invited | active | suspended | archived
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();   // e.g. grievance.resolve
            $table->string('group', 60);            // Stakeholders, Grievances...
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // NULL organisation_id = platform-wide template role.
            $table->foreignId('organisation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            // Escalation ladder position: higher escalates to lower number.
            $table->unsignedTinyInteger('escalation_rank')->default(50);
            $table->timestamps();

            $table->unique(['organisation_id', 'key']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('project_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            // Handling groups gate restricted grievance categories (SEA/SH etc.)
            $table->json('handling_groups')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        // Per-project reference-number allocator (STK-0001, GRV-0001 ...).
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        /*
         * Configuration store. scope = organisation | project.
         * Project rows override organisation rows, which override config/sasa.php.
         */
        Schema::create('configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->json('value');
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'project_id', 'key'], 'configurations_scope_key_unique');
            $table->index(['project_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configurations');
        Schema::dropIfExists('sequences');
        Schema::dropIfExists('project_memberships');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('organisations');
    }
};
