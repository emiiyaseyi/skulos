<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CalendarEvent;
use App\Services\Calendar\CalendarService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    /**
     * Provisioning now seeds BOTH the current and next calendar year (user
     * directive 2026-07-15: "ALWAYS DO IT FOR EVERY YEAR GOING FORWARD
     * TOO"), so a school provisioned late in the year still has next
     * year's holidays ready on day one. Undated, school-decided
     * placeholders (Beginning of Term, ...) are seeded once, ever; dated
     * real holidays get one row per year.
     */
    public function test_provisioning_seeds_this_year_and_next_years_notable_days(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal']));
        app(TenantManager::class)->use($school);

        $dated = collect(config('skulos.default_calendar_events'))->filter(fn ($e) => isset($e['month']) || isset($e['easter_offset']) || isset($e['hijri_month']));
        $undated = collect(config('skulos.default_calendar_events'))->reject(fn ($e) => isset($e['month']) || isset($e['easter_offset']) || isset($e['hijri_month']));

        $expectedCount = ($dated->count() * 2) + $undated->count(); // dated: this year + next; undated: once
        $this->assertSame($expectedCount, CalendarEvent::where('is_default', true)->count());

        foreach (['Beginning of Term', 'Mid Term Break', 'End of Term', 'Art and Culture Day', 'Professional Day', 'Inter House Sports Day'] as $title) {
            $this->assertTrue(CalendarEvent::where('title', $title)->whereNull('starts_on')->exists(), "missing undated default: {$title}");
        }

        $christmasThisYear = CalendarEvent::where('title', 'Christmas Day')->whereYear('starts_on', now()->year)->first();
        $christmasNextYear = CalendarEvent::where('title', 'Christmas Day')->whereYear('starts_on', now()->year + 1)->first();
        $this->assertNotNull($christmasThisYear);
        $this->assertNotNull($christmasNextYear);
        $this->assertSame(12, $christmasThisYear->starts_on->month);
        $this->assertSame(25, $christmasThisYear->starts_on->day);
    }

    public function test_seeding_the_same_year_twice_is_idempotent(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal2']));
        app(TenantManager::class)->use($school);

        $before = CalendarEvent::count();
        $created = app(CalendarService::class)->seedDefaults(now()->year);

        $this->assertSame(0, $created);
        $this->assertSame($before, CalendarEvent::count());
    }

    /** Seeding a NEW year adds new rows for dated holidays, not skipped by an old same-titled row. */
    public function test_seeding_a_new_year_adds_new_dated_rows_without_touching_old_ones(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal2b']));
        app(TenantManager::class)->use($school);

        $futureYear = now()->year + 5;
        $created = app(CalendarService::class)->seedDefaults($futureYear);

        $dated = collect(config('skulos.default_calendar_events'))->filter(fn ($e) => isset($e['month']) || isset($e['easter_offset']) || isset($e['hijri_month']));
        $this->assertSame($dated->count(), $created);

        $newYearsDay = CalendarEvent::where('title', "New Year's Day")->whereYear('starts_on', $futureYear)->first();
        $this->assertNotNull($newYearsDay);
        $this->assertSame($futureYear, $newYearsDay->starts_on->year);

        // The original current-year row is untouched, still there.
        $this->assertTrue(CalendarEvent::where('title', "New Year's Day")->whereYear('starts_on', now()->year)->exists());
    }

    public function test_easter_relative_holidays_use_the_real_computed_easter_date(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal2c']));
        app(TenantManager::class)->use($school);

        $goodFriday = CalendarEvent::where('title', 'Good Friday')->whereYear('starts_on', now()->year)->first();
        $easterMonday = CalendarEvent::where('title', 'Easter Monday')->whereYear('starts_on', now()->year)->first();
        $easterSunday = \App\Services\Calendar\CalendarService::easterDate(now()->year);

        $this->assertSame($easterSunday->copy()->subDays(2)->toDateString(), $goodFriday->starts_on->toDateString());
        $this->assertSame($easterSunday->copy()->addDay()->toDateString(), $easterMonday->starts_on->toDateString());
    }

    /**
     * The three Islamic holidays (user directive 2026-07-15: "ADD ALL
     * PUBLIC HOLIDAYES IN NIGERIA TOO") are seeded with an estimated date
     * that actually falls within the requested Gregorian year, and are
     * labelled "(estimated)" since the real date depends on moon sighting.
     */
    public function test_islamic_holidays_are_seeded_with_an_estimated_date_in_the_right_year(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal2d']));
        app(TenantManager::class)->use($school);

        foreach (['Eid el-Fitr (estimated)', 'Eid el-Kabir (estimated)', 'Eid el-Mawlid (estimated)'] as $title) {
            $event = CalendarEvent::where('title', $title)->whereYear('starts_on', now()->year)->first();
            $this->assertNotNull($event, "missing: {$title}");
            $this->assertSame(now()->year, $event->starts_on->year);
        }
    }

    public function test_admin_can_add_and_date_events_and_query_upcoming(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal3']));
        app(TenantManager::class)->use($school);

        CalendarEvent::create([
            'title' => 'PTA Meeting', 'category' => 'general',
            'starts_on' => now()->addDays(5)->toDateString(),
        ]);
        CalendarEvent::create([
            'title' => 'Past Event', 'category' => 'general',
            'starts_on' => now()->subDays(5)->toDateString(),
        ]);

        $upcoming = CalendarEvent::upcoming()->pluck('title');
        $this->assertContains('PTA Meeting', $upcoming);
        $this->assertNotContains('Past Event', $upcoming);
    }

    public function test_default_events_can_be_dated_by_the_school(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal4']));
        app(TenantManager::class)->use($school);

        $sports = CalendarEvent::where('title', 'Inter House Sports Day')->first();
        $sports->update(['starts_on' => '2026-03-14']);

        $this->assertSame('2026-03-14', $sports->fresh()->starts_on->toDateString());
    }

    /** The scheduled command that keeps every school's calendar topped up going forward. */
    public function test_the_scheduled_command_seeds_a_specific_year_for_every_school(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal5']));

        $farYear = now()->year + 10;
        $this->artisan('skulos:seed-calendar-defaults', ['year' => $farYear])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $this->assertTrue(CalendarEvent::where('title', "New Year's Day")->whereYear('starts_on', $farYear)->exists());
        app(TenantManager::class)->forget();
    }

    /**
     * Real term dates for a real production school (user report 2026-07-16:
     * "CHECK FOR LAGOS STATE OR OGUN STATE ACADEMIC CALENDAR ... TO
     * POPULATE THE RESPECTIVE AND ACTIVE TERMS"). A newly created session's
     * terms start with no dates at all; this command backfills/corrects
     * them from the official Lagos State calendar in
     * config('skulos.official_term_dates').
     */
    public function test_seed_official_term_dates_fills_in_the_real_calendar_dates(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal6']));
        app(TenantManager::class)->use($school);
        $session = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026 Session']);
        foreach (['First' => 1, 'Second' => 2, 'Third' => 3] as $ordinal => $sequence) {
            \App\Models\Tenant\Term::create([
                'academic_session_id' => $session->id,
                'name' => "{$ordinal} Term 2025/2026",
                'sequence' => $sequence,
            ]);
        }
        app(TenantManager::class)->forget();

        $this->artisan('skulos:seed-official-term-dates', ['school' => 'cal6'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $session->refresh();
        $this->assertSame('2025-09-15', $session->starts_on->toDateString());
        $this->assertSame('2026-07-24', $session->ends_on->toDateString());

        $first = $session->terms()->where('sequence', 1)->first();
        $this->assertSame('2025-09-15', $first->starts_on->toDateString());
        $this->assertSame('2025-12-19', $first->ends_on->toDateString());

        $third = $session->terms()->where('sequence', 3)->first();
        $this->assertSame('2026-05-04', $third->starts_on->toDateString());
        $this->assertSame('2026-07-24', $third->ends_on->toDateString());
        app(TenantManager::class)->forget();
    }

    public function test_seed_official_term_dates_is_idempotent_and_skips_schools_without_that_session(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal7']));

        $this->artisan('skulos:seed-official-term-dates', ['school' => 'cal7'])
            ->expectsOutputToContain('cal7: 0 row(s) updated.')
            ->assertSuccessful();
    }
}
