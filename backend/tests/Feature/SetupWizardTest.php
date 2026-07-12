<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\House;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Guided setup wizard: a genuine one-step-at-a-time flow (made linear
 * 2026-07-12 per the "To Do List 2" doc's item 1, "School Setup Wizard",
 * previously this was a single page showing every step's form at once).
 * Every action on the page reuses the existing routes for each piece, this
 * test file checks the wizard's own step navigation and completion
 * detection, not the underlying actions themselves (already covered by
 * their own test files).
 */
class SetupWizardTest extends TestCase
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

    /** A brand new school with nothing set up yet lands on the first not-done step (branding, step 2, step 1 "Welcome" is always done). */
    public function test_a_brand_new_school_lands_on_the_first_not_done_step(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'setupone');

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/setup")
            ->assertOk()
            ->assertSee('Step 2 of 9')
            ->assertSee('Give your school its own colour');
    }

    /** Steps are addressable directly, so the rail's links and Back/Next both work. */
    public function test_a_specific_step_can_be_opened_directly(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'setupstepdirect');

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/setup?step=3")
            ->assertOk()
            ->assertSee('Step 3 of 9')
            ->assertSee('Create your first academic session');
    }

    public function test_the_dashboard_shows_a_finish_setup_banner_until_everything_is_done(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'setuptwo');

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('Finish setup');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $session->makeCurrent();
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        House::create(['name' => 'Red House']);
        AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);
        \App\Models\Tenant\Student::create(['admission_number' => 'ADM/001', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        \App\Models\Tenant\Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'A', 'last_name' => 'B', 'position' => 'Teacher', 'status' => 'active']);
        $school->update(['brand_color' => '#123524']);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertDontSee('Finish setup');
    }

    /** A completed step shows a summary instead of the action form, exactly like the old single page version did per step. */
    public function test_a_completed_step_shows_a_summary_instead_of_the_action_form(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'setupthree');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $session->makeCurrent();
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/setup?step=3")
            ->assertOk()
            ->assertSee('Current session:')
            ->assertSee('2025/2026 Session')
            ->assertDontSee('Create your first academic session');
    }

    /** Once every step is done, visiting with no step lands on the final "Done" step, not step 1. */
    public function test_a_fully_set_up_school_lands_on_the_done_step(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'setupsix');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $session->makeCurrent();
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        House::create(['name' => 'Red House']);
        AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);
        \App\Models\Tenant\Student::create(['admission_number' => 'ADM/001', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        \App\Models\Tenant\Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'A', 'last_name' => 'B', 'position' => 'Teacher', 'status' => 'active']);
        $school->update(['brand_color' => '#123524']);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/setup")
            ->assertOk()
            ->assertSee('Step 9 of 9')
            ->assertSee("You're all set");
    }

    public function test_a_parent_without_academics_view_cannot_see_the_wizard(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'setupfour');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/setup")->assertForbidden();
    }

    /**
     * Registrar holds academics.view but not academics.create, so the
     * session step's action form must not render for it, only the
     * read-only prompt.
     */
    public function test_a_role_that_can_view_but_not_manage_academics_sees_no_action_form_for_that_step(): void
    {
        [$school, $registrar] = $this->schoolWithMember('registrar', 'setupfive');

        $this->actingAs($registrar)->get("http://{$school->slug}.localhost/setup?step=3")
            ->assertOk()
            ->assertDontSee('Create session &amp; terms', false);
    }

    /**
     * The branding screen itself had no RBAC check at all (found while
     * wiring the wizard's branding step to school_info.update): any
     * school member, including a parent login, could open and submit
     * /settings/branding. Fixed on the underlying SchoolPortalController
     * methods, this is the regression test for that fix.
     */
    public function test_a_parent_cannot_open_or_update_the_branding_screen(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'setupseven');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/settings/branding")->assertForbidden();
        $this->actingAs($parent)->post("http://{$school->slug}.localhost/settings/branding", [
            'brand_color' => '#000000',
        ])->assertForbidden();
    }
}
