<?php

namespace Tests\Unit;

use App\Domain\Sla\WorkingCalendarService;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "7 working days" means nothing without a calendar and a holiday list. */
class WorkingCalendarTest extends TestCase
{
    use RefreshDatabase;

    private WorkingCalendarService $calendars;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        $this->calendars = app(WorkingCalendarService::class);
    }

    public function test_adding_working_days_skips_the_weekend(): void
    {
        // Friday 2026-01-02 + 3 working days = Wednesday 2026-01-07
        $from = CarbonImmutable::parse('2026-01-02 09:00', 'Africa/Dar_es_Salaam');

        $due = $this->calendars->addWorkingDays($from, 3, $this->calendar);

        $this->assertSame('2026-01-07', $due->toDateString());
    }

    public function test_adding_working_days_skips_a_public_holiday(): void
    {
        Holiday::create([
            'working_calendar_id' => $this->calendar->id,
            'date' => '2026-01-06',
            'name' => 'Test holiday',
        ]);

        $from = CarbonImmutable::parse('2026-01-02 09:00');

        // Mon 5th, [Tue 6th is a holiday], Wed 7th, Thu 8th
        $this->assertSame('2026-01-08', $this->calendars->addWorkingDays($from, 3, $this->calendar)->toDateString());
    }

    public function test_working_hours_do_not_run_overnight(): void
    {
        // 16:00 on a Monday + 4 working hours lands at 11:00 on the Tuesday,
        // not at 20:00 the same evening.
        $from = CarbonImmutable::parse('2026-01-05 16:00');

        $due = $this->calendars->addWorkingHours($from, 4, $this->calendar);

        $this->assertSame('2026-01-06 11:00', $due->format('Y-m-d H:i'));
    }

    public function test_elapsed_working_time_ignores_evenings_and_weekends(): void
    {
        $start = CarbonImmutable::parse('2026-01-02 15:00'); // Friday
        $end = CarbonImmutable::parse('2026-01-05 10:00');   // Monday

        // 2 hours on Friday + 2 hours on Monday = 4 working hours.
        $this->assertSame(240, $this->calendars->workingMinutesBetween($start, $end, $this->calendar));
    }

    public function test_a_saturday_is_not_a_working_day(): void
    {
        $holidays = $this->calendars->holidays($this->calendar);

        $this->assertFalse($this->calendars->isWorkingDay(CarbonImmutable::parse('2026-01-03'), $this->calendar, $holidays));
        $this->assertTrue($this->calendars->isWorkingDay(CarbonImmutable::parse('2026-01-05'), $this->calendar, $holidays));
    }
}
