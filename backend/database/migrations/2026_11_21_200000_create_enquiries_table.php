<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Register interest" and "Book a demo" from the public website. Platform
 * level: an enquiry belongs to no tenant. The sender's IP is never stored,
 * only a keyed hash of it, so repeated abuse can be spotted without keeping
 * personal data we do not need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 30)->unique();      // ENQ-2026-0001
            $table->string('type', 20);                     // interest | demo | quote
            $table->string('full_name', 160);
            $table->string('email', 190);
            $table->string('organization', 200);
            $table->string('sector', 30);                   // infrastructure | mining | energy | other
            $table->string('phone', 30)->nullable();
            $table->json('modules')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new');   // new | contacted | closed
            $table->text('admin_notes')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('ip_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
