<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\CalendarEvent;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Dashboard "Public holidays" widget (Dashboard Design Cross-Check: "Public
 * Holidays as its own list") was later found to just repeat whatever
 * holidays already showed in the "Upcoming on the calendar" widget beside
 * it (user report 2026-07-16: "similar data under both Upcoming on the
 * calendar and Public holidays, let's retain only Upcoming on the
 * calendar, another data can be used to replace the Public holidays card
 * section"). Replaced with "This term's key dates": the current term's own
 * Beginning of Term/Mid Term Break/End of Term milestones, a genuinely
 * different lens (past and future both, not just what's still ahead).
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

    protected function extractWidget(string $content): string
    {
        $start = strpos($content, "This term's key dates");
        $this->assertNotFalse($start);

        return substr($content, $start, 1400);
    }

    public function test_dashboard_shows_the_current_terms_own_key_dates(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'holone');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $term = Term::create([
            'academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1,
            'starts_on' => now()->subDays(10), 'ends_on' => now()->addDays(20),
        ]);
        $term->makeCurrent();
        CalendarEvent::where('title', 'Beginning of Term')->update(['starts_on' => now()->subDays(10)]);
        CalendarEvent::where('title', 'End of Term')->update(['starts_on' => now()->addDays(20)]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk();

        $widget = $this->extractWidget($response->getContent());
        $this->assertStringContainsString('Beginning of Term', $widget);
        $this->assertStringContainsString('End of Term', $widget);
        $this->assertStringContainsString('Mid Term Break', $widget);
        $this->assertStringContainsString('Done', $widget);
    }

    public function test_empty_state_when_no_current_term_is_set(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'holtwo');

        $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee("This term's key dates", false)
            ->assertSee('No current term set yet');
    }

    public function test_a_key_date_not_yet_set_shows_not_set(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'holfour');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1])->makeCurrent();
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));
        $widget = $this->extractWidget($response->getContent());
        $this->assertStringContainsString('Not set', $widget);
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
            ->assertDontSee("This term's key dates", false);
    }
}
