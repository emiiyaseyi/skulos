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

    public function test_provisioning_seeds_the_default_notable_days(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal']));
        app(TenantManager::class)->use($school);

        $expected = collect(config('skulos.default_calendar_events'))->pluck('title');
        $this->assertSame($expected->count(), CalendarEvent::where('is_default', true)->count());

        foreach (['Beginning of Term', 'Mid Term Break', 'End of Term', "Children's Day", 'Art and Culture Day', 'Professional Day', 'Inter House Sports Day', 'Public Holiday'] as $title) {
            $this->assertTrue(CalendarEvent::where('title', $title)->exists(), "missing default: {$title}");
        }
    }

    public function test_seeding_is_idempotent(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cal2']));
        app(TenantManager::class)->use($school);

        $before = CalendarEvent::count();
        $created = app(CalendarService::class)->seedDefaults();

        $this->assertSame(0, $created);
        $this->assertSame($before, CalendarEvent::count());
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
}
