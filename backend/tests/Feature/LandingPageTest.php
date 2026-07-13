<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admin-configurable default landing page per role (user request
 * 2026-07-13: "Admin-configurable landing page per staff/student/parent
 * (default Dashboard)", extended: "all the changes you added should
 * also reflect for the platform owner too who also have a central
 * override for all the schools"). Precedence: a school's own choice >
 * the platform owner's central default > the Dashboard.
 */
class LandingPageTest extends TestCase
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

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    protected function schoolWithMember(string $roleSlug, string $slug, array $userAttrs = []): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create($userAttrs);
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_a_school_owner_can_set_the_schools_own_landing_page_for_a_role(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'landingone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/landing-pages", [
            'pages' => ['subject-teacher' => 'school.students'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $saved = \App\Models\Tenant\Setting::grab('landing_pages', []);
        app(TenantManager::class)->forget();

        $this->assertSame('school.students', $saved['subject-teacher']);
    }

    public function test_a_subject_teacher_lands_on_the_schools_configured_page_after_login(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'landingtwo');
        $teacher = User::factory()->create(['password' => bcrypt('teachpass123')]);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        \App\Models\Tenant\Setting::put('landing_pages', ['subject-teacher' => 'school.students']);
        app(TenantManager::class)->forget();

        $this->post("http://{$school->slug}.localhost/login", [
            'email' => $teacher->email,
            'password' => 'teachpass123',
        ])->assertRedirect(route('school.students', ['school' => $school->slug]));
    }

    /** No school-level choice, falls back to the platform owner's central default. */
    public function test_the_platform_default_applies_when_the_school_has_not_set_its_own(): void
    {
        PlatformSetting::put('landing_pages', ['subject-teacher' => 'school.students']);

        [$school] = $this->schoolWithMember('school-owner', 'landingthree');
        $teacher = User::factory()->create(['password' => bcrypt('teachpass123')]);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        $this->post("http://{$school->slug}.localhost/login", [
            'email' => $teacher->email,
            'password' => 'teachpass123',
        ])->assertRedirect(route('school.students', ['school' => $school->slug]));
    }

    /** A school's own choice wins over the platform's central default. */
    public function test_a_schools_own_choice_overrides_the_platform_default(): void
    {
        PlatformSetting::put('landing_pages', ['subject-teacher' => 'school.students']);

        [$school] = $this->schoolWithMember('school-owner', 'landingfour');
        $teacher = User::factory()->create(['password' => bcrypt('teachpass123')]);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        \App\Models\Tenant\Setting::put('landing_pages', ['subject-teacher' => 'school.houses.index']);
        app(TenantManager::class)->forget();

        $this->post("http://{$school->slug}.localhost/login", [
            'email' => $teacher->email,
            'password' => 'teachpass123',
        ])->assertRedirect(route('school.houses.index', ['school' => $school->slug]));
    }

    /** Nothing configured anywhere falls back to Dashboard, the existing behaviour. */
    public function test_with_nothing_configured_a_member_still_lands_on_the_dashboard(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'landingfive');
        $teacher = User::factory()->create(['password' => bcrypt('teachpass123')]);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        $this->post("http://{$school->slug}.localhost/login", [
            'email' => $teacher->email,
            'password' => 'teachpass123',
        ])->assertRedirect(route('school.dashboard', ['school' => $school->slug]));
    }

    /** A misconfigured choice the role does not actually hold the permission for degrades to Dashboard instead of 403ing right after login. */
    public function test_a_landing_page_the_role_cannot_access_falls_back_to_dashboard(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'landingsix');
        $teacher = User::factory()->create(['password' => bcrypt('teachpass123')]);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        // subject-teacher does not hold staff.view.
        \App\Models\Tenant\Setting::put('landing_pages', ['subject-teacher' => 'school.staff.index']);
        app(TenantManager::class)->forget();

        $this->post("http://{$school->slug}.localhost/login", [
            'email' => $teacher->email,
            'password' => 'teachpass123',
        ])->assertRedirect(route('school.dashboard', ['school' => $school->slug]));
    }

    public function test_platform_super_admin_can_set_the_central_default(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.landing-pages.update'), [
            'pages' => ['class-teacher' => 'school.report-cards.index'],
        ])->assertRedirect();

        $this->assertSame('school.report-cards.index', PlatformSetting::grab('landing_pages', [])['class-teacher']);
    }

    public function test_a_teacher_without_school_info_manage_settings_cannot_change_landing_pages(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'landingseven');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/settings/landing-pages", [
            'pages' => ['subject-teacher' => 'school.students'],
        ])->assertForbidden();
    }
}
