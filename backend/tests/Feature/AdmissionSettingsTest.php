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
            'password_length' => 14,
            'password_style' => 'numeric',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(
            ['application_started', 'application_submitted', 'documents_uploaded', 'admission_offered', 'acceptance_fee_paid', 'enrolled'],
            Applicant::pipelineChain(),
            'interview stages were unticked and must be gone, the rest must stay in catalog order regardless of submission order'
        );
        $this->assertSame(45, (int) Setting::grab('admission_account_ttl_days'));
        $this->assertSame(14, (int) Setting::grab('admission_password_length'));
        $this->assertSame('numeric', Setting::grab('admission_password_style'));
        app(TenantManager::class)->forget();
    }

    public function test_at_least_one_pipeline_stage_must_stay_enabled(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'astwo');

        $this->actingAs($owner)->post(route('school.admissions.settings.pipeline', ['school' => $school->slug]), [
            'stages' => [],
            'account_ttl_days' => 30,
            'password_length' => 10,
            'password_style' => 'random',
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
            'school_class_id' => [$class->id],
            'application_fee_amount' => 5000,
            'acceptance_fee_amount' => 20000,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $window = AdmissionWindow::first();
        $this->assertNotNull($window);
        $this->assertTrue($window->is_active);
        $this->assertTrue($window->isOpen());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.admissions.settings.windows.toggle', ['school' => $school->slug, 'batchUuid' => $window->batch_uuid]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertFalse($window->fresh()->is_active);
        app(TenantManager::class)->forget();
    }

    public function test_resubmitting_a_class_that_already_has_an_open_window_is_skipped_not_a_crash(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'asseven');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class1 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $class2 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 2]);
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        app(TenantManager::class)->forget();

        $payload = [
            'session_id' => $session->id,
            'school_class_id' => [$class1->id, $class2->id],
            'application_fee_amount' => 5000,
        ];

        $this->actingAs($owner)->post(route('school.admissions.settings.windows.store', ['school' => $school->slug]), $payload)
            ->assertRedirect();

        // Re-submitting with an overlapping class (a real thing a
        // double-click or a resubmitted form can cause) must not crash
        // on the unique constraint — the already-open class is skipped,
        // any genuinely new one still opens.
        app(TenantManager::class)->use($school);
        $class3 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 3', 'sequence' => 3]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->post(route('school.admissions.settings.windows.store', ['school' => $school->slug]), array_merge($payload, [
            'school_class_id' => [$class1->id, $class3->id],
        ]));
        $response->assertRedirect()->assertSessionHas('status', fn ($msg) => str_contains($msg, 'skipped'));

        app(TenantManager::class)->use($school);
        $this->assertSame(3, AdmissionWindow::count(), 'class1+class2 from the first submission, plus class3 from the second; class1 must not duplicate');
        $this->assertSame(0.0, (float) AdmissionWindow::first()->acceptance_fee_amount, 'acceptance fee left blank must default to 0, not be required');
        app(TenantManager::class)->forget();
    }

    public function test_editing_a_window_batch_updates_every_row_in_it(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'aseight');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class1 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $class2 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 2]);
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.admissions.settings.windows.store', ['school' => $school->slug]), [
            'name' => 'Original name',
            'session_id' => $session->id,
            'school_class_id' => [$class1->id, $class2->id],
            'application_fee_amount' => 5000,
        ]);

        app(TenantManager::class)->use($school);
        $batchUuid = AdmissionWindow::first()->batch_uuid;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.admissions.settings.windows.update', ['school' => $school->slug, 'batchUuid' => $batchUuid]), [
            'name' => 'Renamed intake',
            'application_fee_amount' => 7500,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $rows = AdmissionWindow::where('batch_uuid', $batchUuid)->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($w) => $w->name === 'Renamed intake' && (float) $w->application_fee_amount === 7500.0));
        app(TenantManager::class)->forget();
    }

    public function test_selecting_multiple_classes_opens_one_window_per_class(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'asfive');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class1 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $class2 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 2]);
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.admissions.settings.windows.store', ['school' => $school->slug]), [
            'session_id' => $session->id,
            'school_class_id' => [$class1->id, $class2->id],
            'application_fee_amount' => 5000,
            'acceptance_fee_amount' => 20000,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(2, AdmissionWindow::count());
        $this->assertTrue(AdmissionWindow::where('school_class_id', $class1->id)->exists());
        $this->assertTrue(AdmissionWindow::where('school_class_id', $class2->id)->exists());
        app(TenantManager::class)->forget();
    }

    public function test_owner_can_switch_the_reference_number_format(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'assix');

        $this->actingAs($owner)->post(route('school.admissions.settings.reference-format', ['school' => $school->slug]), [
            'reference_style' => 'structured',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame('structured', Setting::grab('admission_reference_style'));
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_without_manage_settings_cannot_reach_admissions_settings(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'asfour');

        $this->actingAs($teacher)->get(route('school.admissions.settings.edit', ['school' => $school->slug]))
            ->assertForbidden();
    }
}
