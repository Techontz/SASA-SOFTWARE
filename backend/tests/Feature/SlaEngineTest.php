<?php

namespace Tests\Feature;

use App\Domain\Sla\SlaEngine;
use App\Models\Grievance;
use App\Models\Holiday;
use App\Models\SlaClock;
use App\Models\SlaPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The engine holds the mechanism; the client sets the numbers. Nothing here is
 * hard-coded to "7 working days".
 */
class SlaEngineTest extends TestCase
{
    use RefreshDatabase;

    private SlaEngine $sla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        $this->sla = app(SlaEngine::class);
    }

    private function makeCase(array $overrides = []): Grievance
    {
        $officer = $this->makeUser('grievance_officer');
        $this->asUser($officer);

        return Grievance::findOrFail(
            $this->postJson('/api/v1/grievances', array_merge([
                'channel' => 'web',
                'confidentiality' => 'normal',
                'title' => 'A case for the SLA tests',
                'description' => 'Something happened and it needs handling inside the project standard.',
                'category_id' => $this->categories['environment']->id,
                'severity' => 3,
            ], $overrides))->json('data.id')
        );
    }

    public function test_the_due_date_is_computed_from_the_working_calendar(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-02 09:00', 'Africa/Dar_es_Salaam')); // Friday
        Carbon::setTestNow('2026-01-02 09:00');

        $case = $this->makeCase();
        $clock = $this->sla->find($case, 'acknowledgement');

        // Two working days from Friday is Tuesday — not Sunday.
        $this->assertSame('2026-01-06', $clock->due_at->toDateString());

        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    }

    public function test_a_public_holiday_extends_the_deadline(): void
    {
        Holiday::create([
            'working_calendar_id' => $this->calendar->id,
            'date' => '2026-01-05',
            'name' => 'Test holiday on the Monday',
        ]);

        Carbon::setTestNow('2026-01-02 09:00');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-02 09:00'));

        $case = $this->makeCase();
        $clock = $this->sla->find($case, 'acknowledgement');

        // Monday is a holiday, so two working days lands on Wednesday.
        $this->assertSame('2026-01-07', $clock->due_at->toDateString());

        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    }

    public function test_a_more_specific_policy_wins(): void
    {
        SlaPolicy::create([
            'organisation_id' => $this->organisation->id,
            'project_id' => $this->project->id,
            'clock' => 'resolution',
            'severity' => 5,
            'unit' => 'working_days',
            'target_value' => 2,
            'working_calendar_id' => $this->calendar->id,
            'is_active' => true,
            'specificity' => SlaPolicy::computeSpecificity(null, 5, $this->project->id),
        ]);

        $routine = $this->makeCase(['severity' => 3]);
        $critical = $this->makeCase(['severity' => 5]);

        $this->assertSame(
            config('sasa.sla.defaults.resolution.value'),
            $this->sla->find($routine, 'resolution')->target_value
        );
        $this->assertSame(2, $this->sla->find($critical, 'resolution')->target_value);
    }

    public function test_a_clock_becomes_breached_once_the_due_instant_passes(): void
    {
        $case = $this->makeCase();
        $clock = $this->sla->find($case, 'acknowledgement');

        $clock->forceFill(['due_at' => now()->subHour()])->save();

        $result = $this->sla->evaluate($clock->fresh());

        $this->assertSame('breached', $result['state']);
        $this->assertTrue($result['changed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sla.state_changed']);
    }

    public function test_the_sweep_escalates_a_breach_and_tells_the_owner(): void
    {
        $case = $this->makeCase();
        $officer = $this->makeUser('grievance_officer');
        $this->asUser($officer)->postJson("/api/v1/grievances/{$case->id}/assign", ['assigned_to_id' => $officer->id]);

        SlaClock::where('subject_id', $case->id)->update(['due_at' => now()->subDay()]);

        $this->artisan('sasa:sla-sweep --sync')->assertSuccessful();

        $case->refresh();
        $this->assertSame('breached', $case->resolution_sla_state);
        $this->assertGreaterThan(0, $case->escalation_level);
        $this->assertDatabaseHas('grievance_escalations', ['grievance_id' => $case->id, 'trigger' => 'sla_breach']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $officer->id]);
    }

    public function test_pausing_records_a_reason_and_extends_the_deadline_by_the_paused_time(): void
    {
        $case = $this->makeCase();
        $originalDue = $this->sla->find($case, 'resolution')->due_at;

        $this->sla->pause($case, 'resolution', 'Awaiting the complainant\'s response.');
        $paused = $this->sla->find($case, 'resolution');

        $this->assertSame('paused', $paused->state);
        $this->assertSame('Awaiting the complainant\'s response.', $paused->pause_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sla.paused']);

        // A paused clock is not evaluated into breach while it is paused.
        $paused->forceFill(['paused_at' => now()->subHours(5)])->save();
        $this->sla->resume($case, 'resolution');

        $resumed = $this->sla->find($case, 'resolution');
        $this->assertSame('running', $resumed->state);
        $this->assertTrue($resumed->due_at->greaterThan($originalDue));
        $this->assertGreaterThan(0, $resumed->paused_seconds_total);
        // The pause is on the record, so it is reportable.
        $this->assertCount(1, $resumed->pause_history);
    }

    public function test_acknowledging_marks_the_clock_met_and_late_acknowledgement_is_distinguished(): void
    {
        $onTime = $this->makeCase();
        $officer = $this->makeUser('grievance_officer');

        $this->asUser($officer)->postJson("/api/v1/grievances/{$onTime->id}/acknowledge", ['method' => 'sms']);
        $this->assertSame('met', $this->sla->find($onTime, 'acknowledgement')->state);

        $late = $this->makeCase();
        SlaClock::where('subject_id', $late->id)->where('clock', 'acknowledgement')
            ->update(['due_at' => now()->subDays(3)]);

        $this->asUser($officer)->postJson("/api/v1/grievances/{$late->id}/acknowledge", ['method' => 'sms']);
        $this->assertSame('met_late', $this->sla->find($late, 'acknowledgement')->state);
    }

    public function test_reminders_fire_once_per_threshold(): void
    {
        Notification::fake();

        $case = $this->makeCase();
        $clock = $this->sla->find($case, 'acknowledgement');

        // 90% of the window elapsed.
        $window = $clock->due_at->getTimestamp() - $clock->started_at->getTimestamp();
        $clock->forceFill(['started_at' => now()->subSeconds((int) ($window * 0.9))])->save();
        $clock->forceFill(['due_at' => now()->addSeconds((int) ($window * 0.1))])->save();

        $first = $this->sla->evaluate($clock->fresh());
        $this->assertNotEmpty($first['thresholds_crossed']);

        $second = $this->sla->evaluate($clock->fresh());
        $this->assertEmpty($second['thresholds_crossed']);
    }

    public function test_closing_a_case_stops_its_clocks(): void
    {
        $case = $this->makeCase();
        $officer = $this->makeUser('grievance_officer');

        $this->asUser($officer)->postJson("/api/v1/grievances/{$case->id}/resolve", [
            'resolution_summary' => 'Resolved on site with the complainant present and satisfied.',
        ]);
        $this->asUser($officer)->postJson("/api/v1/grievances/{$case->id}/close");

        $this->assertSame('cancelled', $this->sla->find($case->fresh(), 'acknowledgement')->state);
    }
}
