<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo workspaces. A company that asked for a demo gets its own organisation
 * and project, filled with sample data, for a limited time. When the demo is
 * expired or ended its project refuses every request; when the company signs
 * an agreement the demo is converted and the workspace simply carries on.
 *
 * Invitations ("set your password") get their own token table, so their
 * longer life never stretches an ordinary forgot-password link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('status');
        });

        Schema::create('demos', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('enquiry_id')->nullable()->constrained('enquiries')->nullOnDelete();
            $table->foreignId('organisation_id')->constrained('organisations');
            $table->foreignId('project_id')->constrained('projects');
            $table->foreignId('user_id')->constrained('users');
            $table->string('status', 20)->default('active');  // active | expired | ended | converted
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'ends_at']);
            $table->index(['project_id', 'status']);
            $table->index(['enquiry_id', 'status']);
            $table->index(['organisation_id', 'status']);
        });

        Schema::create('password_invite_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_invite_tokens');
        Schema::dropIfExists('demos');

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
