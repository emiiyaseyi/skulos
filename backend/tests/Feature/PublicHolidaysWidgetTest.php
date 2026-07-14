<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CalendarEvent;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Dashboard Design Cross-Check: "Public Holidays as its own list", separate
 * from the general "Upcoming on the calendar" widget which mixes every
 * category together.
 */
class PublicHolidaysWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        $this->seed(RbacSeeder::class);
        $this->rbac = app(Rbac::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithMember(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_dashboard_shows_only_holiday_category_events_in_the_holidays_widget(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'holone');

        app(TenantManager::class)->use($school);
        CalendarEvent::create(['title' => 'Independence Day', 'category' => 'holiday', 'starts_on' => now()->addDays(5), 'all_day' => true]);
        CalendarEvent::create(['title' => 'PTA Meeting', 'category' => 'meeting', 'starts_on' => now()->addDays(3), 'all_day' => true]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk();

        $content = $response->getContent();
        $holidaysStart = strpos($content, 'Public holidays');
        $this->assertNotFalse($holidaysStart);
        $holidaysSection = substr($content, $holidaysStart, 400);
        $this->assertStringContainsString('Independence Day', $holidaysSection);
        $this->assertStringNotContainsString('PTA Meeting', $holidaysSection);
    }

    public function test_empty_state_when_no_holidays_are_dated_yet(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'holtwo');

        $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Public holidays')
            ->assertSee('No dated holidays yet');
    }

    public function test_widget_can_be_turned_off_from_dashboard_look_settings(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'holthree');

        $this->actingAs($owner)->post(route('school.dashboard.update', ['school' => $school->slug]), [
            'layout' => 'classic',
            'widgets' => ['greeting' => '1', 'stats' => '1', 'notifications' => '1', 'calendar' => '1'],
        ]);

        $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]))
            ->assertOk()
            ->assertDontSee('Public holidays');
    }
}
