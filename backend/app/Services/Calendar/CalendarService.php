<?php

namespace App\Services\Calendar;

use App\Models\Tenant\CalendarEvent;
use IntlCalendar;
use Illuminate\Support\Carbon;

/**
 * School calendar helpers. seedDefaults() populates a school's calendar
 * with the default notable days from config for a given Gregorian year; it
 * is idempotent so it never duplicates an already-seeded year, and it
 * never touches events a school added or edited itself.
 *
 * User directive 2026-07-15: "ALWAYS DO IT FOR EVERY YEAR GOING FORWARD
 * TOO. ITS DEFAULT" — this is why dated holidays dedupe per (title, year)
 * rather than per title alone, calling seedDefaults() again for a new year
 * adds that year's occurrence rather than being silently skipped because a
 * same-titled row already exists from a prior year. See
 * App\Console\Commands\SeedCalendarDefaults, the scheduled command that
 * keeps every school's calendar topped up automatically.
 */
class CalendarService
{
    public function seedDefaults(?int $year = null): int
    {
        $year ??= now()->year;
        $easter = self::easterDate($year);
        $created = 0;

        foreach (config('skulos.default_calendar_events', []) as $event) {
            $startsOn = $this->resolveDate($event, $year, $easter);

            $query = CalendarEvent::where('is_default', true)->where('title', $event['title']);
            if ($startsOn) {
                // A real, dated, yearly-recurring holiday: dedupe by
                // title + year, so seeding the next year adds a new row
                // instead of finding last year's same-titled one and
                // skipping. An undated, school-decided placeholder
                // (Beginning of Term, Mid Term Break, ...) has no
                // $startsOn, and keeps the original title-only dedupe, it
                // is seeded once ever, a school edits that one row itself.
                $query->whereYear('starts_on', $year);
            }
            if ($query->exists()) {
                continue;
            }

            CalendarEvent::create([
                'title' => $event['title'],
                'category' => $event['category'] ?? 'general',
                'is_default' => true,
                'all_day' => true,
                'starts_on' => $startsOn,
            ]);
            $created++;
        }

        return $created;
    }

    protected function resolveDate(array $event, int $year, Carbon $easter): ?Carbon
    {
        if (isset($event['month'], $event['day'])) {
            return Carbon::create($year, $event['month'], $event['day']);
        }
        if (isset($event['easter_offset'])) {
            return $easter->copy()->addDays($event['easter_offset']);
        }
        if (isset($event['hijri_month'], $event['hijri_day'])) {
            return self::islamicHolidayDate($year, $event['hijri_month'], $event['hijri_day']);
        }

        return null;
    }

    /**
     * The Gregorian date of Easter Sunday for $year, pure arithmetic (the
     * Anonymous Gregorian / Meeus-Jones-Butcher algorithm), not PHP's
     * built-in easter_date(), which needs the optional `calendar`
     * extension that is not confirmed enabled in every environment this
     * project runs in (not in this project's own local php.ini extension
     * list, and often disabled on shared cPanel hosting too).
     */
    public static function easterDate(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $n = $h + $l - 7 * $m + 114;
        $month = intdiv($n, 31);
        $day = ($n % 31) + 1;

        return Carbon::create($year, $month, $day);
    }

    /**
     * The estimated Gregorian date of a Hijri month/day within
     * $gregorianYear, using ICU's tabular "islamic-civil" calendar (a
     * deterministic arithmetic approximation of the Hijri calendar, the
     * same estimation method most software uses to show an Islamic
     * holiday in advance). $hijriMonth is 0-indexed (0=Muharram ...
     * 9=Shawwal ... 11=Dhu al-Hijjah), matching ICU's own convention.
     *
     * This is an ESTIMATE, not an official date: Nigeria's real public
     * holiday for Eid el-Fitr/Eid el-Kabir/Eid el-Mawlid is confirmed only
     * once the moon is actually sighted, typically a day or two before,
     * sometimes shifting the tabular estimate by a day. The seeded title
     * says "(estimated)" for exactly this reason, and the date is
     * editable like any other calendar row once the real date is known.
     *
     * Tries every Hijri year that overlaps $gregorianYear (a Hijri year is
     * ~11 days shorter than a Gregorian one, so which Hijri year produces
     * a date inside a given Gregorian year shifts, rather than a fixed
     * offset) and returns the one whose computed date actually falls
     * within it. Returns null in the (practically impossible for these
     * three holidays) case none do.
     */
    public static function islamicHolidayDate(int $gregorianYear, int $hijriMonth, int $hijriDay): ?Carbon
    {
        $locale = 'en_US@calendar=islamic-civil';

        $jan1 = IntlCalendar::createInstance('UTC', $locale);
        $jan1->setTime(Carbon::create($gregorianYear, 1, 1)->timestamp * 1000);
        $dec31 = IntlCalendar::createInstance('UTC', $locale);
        $dec31->setTime(Carbon::create($gregorianYear, 12, 31)->timestamp * 1000);

        $hijriYearStart = $jan1->get(IntlCalendar::FIELD_YEAR);
        $hijriYearEnd = $dec31->get(IntlCalendar::FIELD_YEAR);

        for ($hijriYear = $hijriYearStart; $hijriYear <= $hijriYearEnd; $hijriYear++) {
            $cal = IntlCalendar::createInstance('UTC', $locale);
            $cal->clear();
            $cal->set($hijriYear, $hijriMonth, $hijriDay);
            $date = Carbon::createFromTimestampUTC(intdiv((int) $cal->getTime(), 1000));

            if ($date->year === $gregorianYear) {
                return $date->startOfDay();
            }
        }

        return null;
    }
}
