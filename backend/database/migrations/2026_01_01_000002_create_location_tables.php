<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Location hierarchy (country > region > district > ward > village) plus the
 * working calendar the SLA engine counts against. "7 working days" is
 * meaningless without a calendar and a public-holiday list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            // NULL project_id = shared across the organisation's projects.
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->cascadeOnDelete();
            $table->string('level', 20); // country | region | district | ward | village
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->string('path', 500)->nullable(); // "Tanzania / Mwanza / Ilemela / Buswelu"
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('estimated_population')->nullable();
            // Estimated share of project-affected population per vulnerable group,
            // used by the uptake-equity view on the disaggregation dashboard.
            $table->json('population_profile')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['organisation_id', 'project_id', 'level'], 'locations_scope_level_idx');
            $table->index(['parent_id', 'name']);
            $table->index('path');
        });

        Schema::create('working_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('country', 2)->nullable();
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->json('working_days');   // [1,2,3,4,5] ISO-8601 day numbers
            $table->time('work_start')->default('08:00');
            $table->time('work_end')->default('17:00');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['organisation_id', 'project_id']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('working_calendar_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->boolean('recurs_annually')->default(false);
            $table->timestamps();

            $table->unique(['working_calendar_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('working_calendars');
        Schema::dropIfExists('locations');
    }
};
