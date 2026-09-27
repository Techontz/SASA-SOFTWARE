<?php

namespace App\Domain\Sla;

use App\Models\Holiday;
use App\Models\Project;
use App\Models\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Working-day and working-hour arithmetic. "7 working days" means nothing
 * without a working week, working hours and a public-holiday list — so all
 * three are configured per project and per country.
 */
final class WorkingCalendarService
{
    public function calendarFor(Project $project): WorkingCalendar
    {
        return Cache::remember(
            "sasa.calendar.project.{$project->id}",
            300,
            fn () => WorkingCalendar::query()
                ->where('organisation_id', $project->organisation_id)
                ->where(fn ($q) => $q->where('project_id', $project->id)->orWhereNull('project_id'))
                ->orderByRaw('project_id IS NULL')
                ->orderByDesc('is_default')
                ->first() ?? $this->fallbackCalendar($project)
        );
    }

    private function fallbackCalendar(Project $project): WorkingCalendar
    {
        return WorkingCalendar::create([
            'organisation_id' => $project->organisation_id,
            'project_id' => $project->id,
            'name' => $project->name.' calendar',
            'country' => $project->country,
            'timezone' => $project->timezone ?: config('sasa.calendar.timezone'),
            'working_days' => config('sasa.calendar.working_days'),
            'work_start' => config('sasa.calendar.working_hours.start'),
            'work_end' => config('sasa.calendar.working_hours.end'),
            'is_default' => true,
        ]);
    }

    /** @return array<int,string> Y-m-d holiday dates for the calendar. */
    public function holidays(WorkingCalendar $calendar): array
    {
        return Cache::remember(
            "sasa.calendar.holidays.{$calendar->id}",
            600,
            fn () => Holiday::where('working_calendar_id', $calendar->id)
                ->get()
                ->flatMap(function (Holiday $holiday) {
                    if (! $holiday->recurs_annually) {
                        return [$holiday->date->toDateString()];
                    }

                    // Project a recurring holiday across a practical window.
                    $dates = [];
                    for ($year = now()->year - 2; $year <= now()->year + 3; $year++) {
                        $dates[] = $holiday->date->copy()->setYear($year)->toDateString();
                    }

                    return $dates;
                })
                ->unique()
                ->values()
                ->all()
        );
    }

    public function isWorkingDay(CarbonImmutable $date, WorkingCalendar $calendar, array $holidays): bool
    {
        if (! in_array((int) $date->dayOfWeekIso, array_map('intval', $calendar->working_days ?? []), true)) {
            return false;
        }

        return ! in_array($date->toDateString(), $holidays, true);
    }

    /**
     * Add N working days to an instant, landing at the same time of day on the
     * target working day (clamped into working hours).
     */
    public function addWorkingDays(CarbonImmutable $from, int $days, WorkingCalendar $calendar): CarbonImmutable
    {
        $holidays = $this->holidays($calendar);
        $cursor = $from;
        $remaining = max(0, $days);
        $guard = 0;

        while ($remaining > 0 && $guard++ < 3650) {
            $cursor = $cursor->addDay();

            if ($this->isWorkingDay($cursor, $calendar, $holidays)) {
                $remaining--;
            }
        }

        // If the start was outside working hours, land at the end of the
        // target working day rather than at an hour nobody is at work.
        return $this->clampToWorkingHours($cursor, $calendar);
    }

    /** Add N working HOURS, skipping non-working hours and non-working days. */
    public function addWorkingHours(CarbonImmutable $from, int $hours, WorkingCalendar $calendar): CarbonImmutable
    {
        $holidays = $this->holidays($calendar);
        $cursor = $this->nextWorkingInstant($from, $calendar, $holidays);
        $remaining = $hours * 60;
        $guard = 0;

        while ($remaining > 0 && $guard++ < 100000) {
            $endOfDay = $this->workEnd($cursor, $calendar);
            $availableMinutes = max(0, $cursor->diffInMinutes($endOfDay, false));

            if ($availableMinutes >= $remaining) {
                return $cursor->addMinutes($remaining);
            }

            $remaining -= $availableMinutes;
            $cursor = $this->nextWorkingInstant($cursor->addDay()->startOfDay(), $calendar, $holidays);
        }

        return $cursor;
    }

    /** Working minutes actually elapsed between two instants. */
    public function workingMinutesBetween(CarbonImmutable $start, CarbonImmutable $end, WorkingCalendar $calendar): int
    {
        if ($end <= $start) {
            return 0;
        }

        $holidays = $this->holidays($calendar);
        $minutes = 0;
        $cursor = $start;
        $guard = 0;

        while ($cursor < $end && $guard++ < 3650) {
            if ($this->isWorkingDay($cursor, $calendar, $holidays)) {
                $dayStart = max($cursor, $this->workStart($cursor, $calendar));
                $dayEnd = min($end, $this->workEnd($cursor, $calendar));

                if ($dayEnd > $dayStart) {
                    $minutes += (int) $dayStart->diffInMinutes($dayEnd);
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $minutes;
    }

    public function workingDaysBetween(CarbonImmutable $start, CarbonImmutable $end, WorkingCalendar $calendar): float
    {
        $minutesPerDay = max(1, (int) $this->workStart($start, $calendar)->diffInMinutes($this->workEnd($start, $calendar)));

        return round($this->workingMinutesBetween($start, $end, $calendar) / $minutesPerDay, 2);
    }

    private function nextWorkingInstant(CarbonImmutable $from, WorkingCalendar $calendar, array $holidays): CarbonImmutable
    {
        $cursor = $from;
        $guard = 0;

        while ($guard++ < 3650) {
            if ($this->isWorkingDay($cursor, $calendar, $holidays)) {
                $start = $this->workStart($cursor, $calendar);
                $end = $this->workEnd($cursor, $calendar);

                if ($cursor < $start) {
                    return $start;
                }

                if ($cursor < $end) {
                    return $cursor;
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $cursor;
    }

    private function clampToWorkingHours(CarbonImmutable $instant, WorkingCalendar $calendar): CarbonImmutable
    {
        $start = $this->workStart($instant, $calendar);
        $end = $this->workEnd($instant, $calendar);

        return match (true) {
            $instant < $start => $start,
            $instant > $end => $end,
            default => $instant,
        };
    }

    private function workStart(CarbonImmutable $date, WorkingCalendar $calendar): CarbonImmutable
    {
        [$h, $m] = array_pad(explode(':', (string) $calendar->work_start), 2, '0');

        return $date->setTime((int) $h, (int) $m);
    }

    private function workEnd(CarbonImmutable $date, WorkingCalendar $calendar): CarbonImmutable
    {
        [$h, $m] = array_pad(explode(':', (string) $calendar->work_end), 2, '0');

        return $date->setTime((int) $h, (int) $m);
    }
}
