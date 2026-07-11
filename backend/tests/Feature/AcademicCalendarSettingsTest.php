<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Sessions & Terms settings page (user request 2026-07-11): this setup
 * information moved out of Classes & Subjects into Settings, and now
 * suggests the current term automatically once a school has entered term
 * dates, or otherwise leaves it to manual "Make current" selection.
 */
class AcademicCalendarSettingsTest extends TestCase
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

    public function test_the_settings_page_is_reachable_and_no_longer_on_classes_and_subjects(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'calone');

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/settings/calendar")
            ->assertOk()->assertSee('Sessions &amp; Terms', false);

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/academics")
            ->assertOk()->assertDontSee('Create session &amp; terms', false);
    }

    public function test_admin_can_save_term_dates(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'caltwo');
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2025]);

        app(TenantManager::class)->use($school);
        $term = Term::first();
        $termId = $term->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/settings/calendar/terms/{$termId}/dates", [
            'starts_on' => '2025-09-01',
            'ends_on' => '2025-12-12',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $term = Term::find($termId);
        app(TenantManager::class)->forget();

        $this->assertSame('2025-09-01', $term->starts_on->toDateString());
        $this->assertSame('2025-12-12', $term->ends_on->toDateString());
    }

    public function test_a_term_covering_today_is_suggested_when_not_already_current(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'calthree');
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2024]);
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2025]);

        app(TenantManager::class)->use($school);
        $newerSession = AcademicSession::where('name', '2025/2026 Session')->first();
        $termCoveringToday = Term::where('academic_session_id', $newerSession->id)->orderBy('sequence')->first();
        $termCoveringToday->update([
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
        ]);
        $termName = $termCoveringToday->name;
        app(TenantManager::class)->forget();

        // 2024/2025 Session is current by default (created first), so the
        // term covering today (in the other session) should be suggested.
        $this->actingAs($admin)->get("http://{$school->slug}.localhost/settings/calendar")
            ->assertOk()
            ->assertSee($termName)
            ->assertSee('Set as current');
    }

    public function test_no_suggestion_shown_when_the_covering_term_is_already_current(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'calfour');
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2025]);

        app(TenantManager::class)->use($school);
        $term = Term::first();
        $termId = $term->id;
        $term->update([
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
        ]);
        app(TenantManager::class)->forget();

        // A session being current does not automatically make one of its
        // terms current too, so make this one current explicitly first.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/terms/{$termId}/make-current");

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/settings/calendar")
            ->assertOk()
            ->assertDontSee('Set as current');
    }

    public function test_a_teacher_without_academics_update_cannot_save_term_dates(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'calfive');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term 2025/2026', 'sequence' => 1]);
        $termId = $term->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/settings/calendar/terms/{$termId}/dates", [
            'starts_on' => '2025-09-01',
        ])->assertForbidden();
    }
}
