<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Setting;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admissions module, Phase 1b: which pipeline stages apply, how long an
 * applicant's account window stays open, and which class/session cycles
 * are open right now with what application fee.
 */
class AdmissionSettingsTest extends TestCase
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
        // No PlanSeeder run in these tests (only RbacSeeder), so there is
        // no default plan for Rbac::moduleState()'s plan fallback to find
        // ('admissions' also is not yet included in any real plan's
        // modules array, a genuine pricing/tier decision, not something
        // to guess at here); enable the module explicitly instead, same
        // pattern FinanceTest already established for this exact gap.
        $this->rbac->setModuleState($school, 'admissions', \App\Models\Module::STATE_ENABLED);

        return [$school, $user];
    }

    public function test_owner_can_toggle_pipeline_stages_and_the_account_ttl(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'asone');

        $this->actingAs($owner)->get(route('school.admissions.settings.edit', ['school' => $school->slug]))
            ->assertOk()->assertSee('Admission pipeline');

        $this->actingAs($owner)->post(route('school.admissions.settings.pipeline', ['school' => $school->slug]), [
            'stages' => ['application_started', 'application_submitted', 'documents_uploaded', 'admission_offered', 'acceptance_fee_paid', 'enrolled'],
            'account_ttl_days' => 45,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(
            ['application_started', 'application_submitted', 'documents_uploaded', 'admission_offered', 'acceptance_fee_paid', 'enrolled'],
            Applicant::pipelineChain(),
            'interview stages were unticked and must be gone, the rest must stay in catalog order regardless of submission order'
        );
        $this->assertSame(45, (int) Setting::grab('admission_account_ttl_days'));
        app(TenantManager::class)->forget();
    }

    public function test_at_least_one_pipeline_stage_must_stay_enabled(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'astwo');

        $this->actingAs($owner)->post(route('school.admissions.settings.pipeline', ['school' => $school->slug]), [
            'stages' => [],
            'account_ttl_days' => 30,
        ])->assertSessionHasErrors('stages');
    }

    public function test_owner_can_open_and_close_an_admission_window(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'asthree');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.admissions.settings.windows.store', ['school' => $school->slug]), [
            'session_id' => $session->id,
            'school_class_id' => $class->id,
            'application_fee_amount' => 5000,
            'acceptance_fee_amount' => 20000,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $window = AdmissionWindow::first();
        $this->assertNotNull($window);
        $this->assertTrue($window->is_active);
        $this->assertTrue($window->isOpen());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.admissions.settings.windows.toggle', ['school' => $school->slug, 'windowId' => $window->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertFalse($window->fresh()->is_active);
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_without_manage_settings_cannot_reach_admissions_settings(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'asfour');

        $this->actingAs($teacher)->get(route('school.admissions.settings.edit', ['school' => $school->slug]))
            ->assertForbidden();
    }
}
