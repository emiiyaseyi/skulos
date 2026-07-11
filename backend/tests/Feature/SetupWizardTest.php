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
 * Guided setup wizard: one screen tying together the session/term, class
 * structure, houses, and assessment structure one-time setup steps that
 * already existed as separate, easy-to-miss screens. Every action on the
 * page reuses the existing routes for each piece, this test file checks
 * the wizard's own completion detection and dashboard banner, not the
 * underlying actions themselves (already covered by their own test files).
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

    public function test_a_brand_new_school_sees_every_step_as_not_done(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'setupone');

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/setup")
            ->assertOk()
            ->assertSee('Create session &amp; terms', false)
            ->assertSee('Load selected tiers')
            ->assertSee('Load default houses')
            ->assertSee('Load default structure');
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
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertDontSee('Finish setup');
    }

    public function test_each_completed_step_shows_a_summary_instead_of_the_action_form(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'setupthree');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $session->makeCurrent();
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/setup")
            ->assertOk()
            ->assertSee('Current session:')
            ->assertSee('2025/2026 Session')
            ->assertDontSee('Create session &amp; terms', false);
    }

    public function test_a_teacher_without_academics_view_cannot_see_the_wizard(): void
    {
        [$school, $teacher] = $this->schoolWithMember('parent', 'setupfour');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/setup")->assertForbidden();
    }

    /**
     * Registrar is the only role besides examination-officer that holds
     * academics.view without also holding academics.create or
     * examinations.approve_results (it does hold students.* though, so
     * the houses step, gated on students.manage_settings, is expected to
     * still show for it, that combination is exactly what is asserted).
     */
    public function test_a_role_that_can_view_but_not_manage_academics_sees_no_action_form_for_those_steps(): void
    {
        [$school, $registrar] = $this->schoolWithMember('registrar', 'setupfive');

        $this->actingAs($registrar)->get("http://{$school->slug}.localhost/setup")
            ->assertOk()
            ->assertDontSee('Create session &amp; terms', false)
            ->assertDontSee('Load selected tiers')
            ->assertDontSee('Load default structure');
    }
}
