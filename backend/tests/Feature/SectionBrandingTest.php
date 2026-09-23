<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\SchoolSectionBranding;
use App\Models\Tenant\Section;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\ReportCards\ReportCardService;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per-section branding (user directive 2026-09-09): a school running,
 * say, Nursery & Primary under one identity and Secondary under another
 * can give each its own logo/colour, with the report card resolving the
 * logo by the student's own class -> section, and a school-level toggle
 * for whether the login page shows all distinct logos or just the one
 * default.
 */
class SectionBrandingTest extends TestCase
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

    protected function makeTwoSections(): array
    {
        $nurseryPrimary = Section::create(['name' => 'Nursery & Primary', 'sequence' => 1]);
        $secondary = Section::create(['name' => 'Secondary', 'sequence' => 2]);

        return [$nurseryPrimary, $secondary];
    }

    public function test_a_logo_uploaded_with_no_scope_still_becomes_the_whole_school_default(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'secbrand1');
        app(TenantManager::class)->use($school);
        $this->makeTwoSections();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/branding", [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertRedirect();

        $school->refresh();
        $this->assertNotNull($school->logo_path);

        app(TenantManager::class)->use($school);
        $this->assertSame(0, SchoolSectionBranding::count());
        app(TenantManager::class)->forget();
    }

    public function test_a_logo_scoped_to_specific_sections_creates_overrides_and_leaves_the_school_default_alone(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'secbrand2');
        app(TenantManager::class)->use($school);
        [$nurseryPrimary, $secondary] = $this->makeTwoSections();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/branding", [
            'logo' => UploadedFile::fake()->image('secondary-logo.png'),
            'logo_scope' => 'sections',
            'logo_sections' => [$secondary->id],
        ])->assertRedirect();

        $school->refresh();
        $this->assertNull($school->logo_path);

        app(TenantManager::class)->use($school);
        $override = SchoolSectionBranding::where('section_id', $secondary->id)->first();
        $this->assertNotNull($override);
        $this->assertNotNull($override->logo_path);
        $this->assertNull(SchoolSectionBranding::where('section_id', $nurseryPrimary->id)->first());
        app(TenantManager::class)->forget();
    }

    public function test_a_section_can_get_its_own_logo_from_its_own_row_and_go_back_to_the_school_logo(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'secbrandrow1');
        app(TenantManager::class)->use($school);
        [$nurseryPrimary, $secondary] = $this->makeTwoSections();
        app(TenantManager::class)->forget();

        // Both screens show the per section list.
        foreach (['settings/branding', 'settings/profile'] as $page) {
            $this->actingAs($owner)->get("http://{$school->slug}.localhost/{$page}")
                ->assertOk()->assertSee('Section logos')->assertSee('Upload logo');
        }

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/branding/sections/{$secondary->id}/logo", [
            'logo' => UploadedFile::fake()->image('secondary.png'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        app(TenantManager::class)->use($school);
        $this->assertNotNull(SchoolSectionBranding::where('section_id', $secondary->id)->value('logo_path'));
        $this->assertNull(SchoolSectionBranding::where('section_id', $nurseryPrimary->id)->first());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/branding/sections/{$secondary->id}/logo/remove")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull(SchoolSectionBranding::where('section_id', $secondary->id)->value('logo_path'));
        app(TenantManager::class)->forget();
    }

    /**
     * A logo already uploaded as the school default can be assigned to
     * a section without re-choosing the same file (user report
     * 2026-09-22, live: "i change the school logo to apply to only
     * secondary section and i clicked on save... it did not save" --
     * the old code only ever acted when a NEW file was chosen, doing
     * nothing at all if the admin was just reassigning the existing one).
     */
    public function test_the_schools_current_logo_can_be_assigned_to_a_section_without_a_new_upload(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'secbrandreuse1');
        app(TenantManager::class)->use($school);
        [$nurseryPrimary, $secondary] = $this->makeTwoSections();
        app(TenantManager::class)->forget();

        $school->logo_path = 'schools/'.$school->id.'/already-there.png';
        $school->save();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/branding", [
            'logo_scope' => 'sections',
            'logo_sections' => [$secondary->id],
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        app(TenantManager::class)->use($school);
        $override = SchoolSectionBranding::where('section_id', $secondary->id)->first();
        $this->assertNotNull($override);
        $this->assertSame('schools/'.$school->id.'/already-there.png', $override->logo_path);
        $this->assertNull(SchoolSectionBranding::where('section_id', $nurseryPrimary->id)->first());
        app(TenantManager::class)->forget();
    }

    public function test_assigning_a_section_logo_with_no_school_default_and_no_new_file_is_rejected(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'secbrandreuse2');
        app(TenantManager::class)->use($school);
        [, $secondary] = $this->makeTwoSections();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/branding", [
            'logo_scope' => 'sections',
            'logo_sections' => [$secondary->id],
        ])->assertRedirect()->assertSessionHasErrors('logo');
    }

    /** Same reuse behaviour, reachable from School Profile too (user directive: "update both the school profile and branding page"). */
    public function test_the_schools_current_logo_can_be_assigned_to_a_section_from_school_profile_too(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'secbrandreuse3');
        app(TenantManager::class)->use($school);
        [, $secondary] = $this->makeTwoSections();
        app(TenantManager::class)->forget();

        $school->logo_path = 'schools/'.$school->id.'/already-there.png';
        $school->save();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/profile", [
            'logo_scope' => 'sections',
            'logo_sections' => [$secondary->id],
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        app(TenantManager::class)->use($school);
        $override = SchoolSectionBranding::where('section_id', $secondary->id)->first();
        $this->assertSame('schools/'.$school->id.'/already-there.png', $override?->logo_path);
        app(TenantManager::class)->forget();
    }

    public function test_choosing_sections_scope_with_none_ticked_is_rejected_rather_than_silently_applied_school_wide(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'secbrand3');
        app(TenantManager::class)->use($school);
        $this->makeTwoSections();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/branding", [
            'logo' => UploadedFile::fake()->image('logo.png'),
            'logo_scope' => 'sections',
        ])->assertRedirect()->assertSessionHasErrors('logo_sections');

        $school->refresh();
        $this->assertNull($school->logo_path);
    }

    public function test_resetting_a_sections_branding_removes_its_override(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'secbrand4');
        app(TenantManager::class)->use($school);
        [, $secondary] = $this->makeTwoSections();
        SchoolSectionBranding::create(['section_id' => $secondary->id, 'brand_color' => '#112233']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/branding/sections/{$secondary->id}/reset", [
            'school' => $school->slug,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull(SchoolSectionBranding::where('section_id', $secondary->id)->first());
        app(TenantManager::class)->forget();
    }

    public function test_the_report_card_uses_the_students_own_section_logo_not_the_school_default(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'secbrand5');
        app(TenantManager::class)->use($school);

        $school->update(['logo_path' => 'schools/1/default-logo.png']);
        [, $secondary] = $this->makeTwoSections();
        SchoolSectionBranding::create(['section_id' => $secondary->id, 'logo_path' => 'schools/1/secondary-logo.png']);
        $scale = \App\Models\Tenant\GradingScale::create(['name' => 'Standard', 'is_default' => true]);
        \App\Models\Tenant\GradingBand::create(['grading_scale_id' => $scale->id, 'min_score' => 0, 'max_score' => 100, 'grade' => 'A', 'remark' => 'Good']);

        $class = SchoolClass::create(['section_id' => $secondary->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $student = Student::create([
            'admission_number' => 'ADM/SEC1', 'first_name' => 'Tolu', 'last_name' => 'Bello',
            'current_class_arm_id' => $classArm->id,
        ]);

        $term = \App\Models\Tenant\Term::create([
            'academic_session_id' => \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026'])->id,
            'name' => 'First Term', 'sequence' => 1,
        ]);

        $data = app(ReportCardService::class)->forStudent($student, $term);
        app(TenantManager::class)->forget();

        $this->assertStringContainsString('secondary-logo.png', $data['logo_url']);
    }

    public function test_the_report_card_falls_back_to_the_school_default_when_the_students_section_has_no_override(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'secbrand6');
        app(TenantManager::class)->use($school);

        $school->update(['logo_path' => 'schools/1/default-logo.png']);
        [$nurseryPrimary] = $this->makeTwoSections();
        $scale = \App\Models\Tenant\GradingScale::create(['name' => 'Standard', 'is_default' => true]);
        \App\Models\Tenant\GradingBand::create(['grading_scale_id' => $scale->id, 'min_score' => 0, 'max_score' => 100, 'grade' => 'A', 'remark' => 'Good']);

        $class = SchoolClass::create(['section_id' => $nurseryPrimary->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $student = Student::create([
            'admission_number' => 'ADM/PRI1', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'current_class_arm_id' => $classArm->id,
        ]);

        $term = \App\Models\Tenant\Term::create([
            'academic_session_id' => \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026'])->id,
            'name' => 'First Term', 'sequence' => 1,
        ]);

        $data = app(ReportCardService::class)->forStudent($student, $term);
        app(TenantManager::class)->forget();

        $this->assertStringContainsString('default-logo.png', $data['logo_url']);
    }

    public function test_login_page_shows_a_single_logo_when_the_toggle_is_off_even_with_a_section_override(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'secbrand7');
        app(TenantManager::class)->use($school);
        [, $secondary] = $this->makeTwoSections();
        SchoolSectionBranding::create(['section_id' => $secondary->id, 'logo_path' => 'schools/1/secondary-logo.png']);
        app(TenantManager::class)->forget();

        $response = $this->get("http://{$school->slug}.localhost/login");
        $response->assertOk();
        // 'lp-logo-lockup' alone would also match the CSS rule in the
        // page's own <style> block, which is always present regardless
        // of which branch rendered -- searching for the opening tag
        // itself is what actually proves (or disproves) the lockup div
        // was rendered.
        $response->assertDontSee('class="lp-logo-lockup">', false);
    }

    public function test_login_page_shows_the_lockup_when_the_toggle_is_on_and_sections_genuinely_differ(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'secbrand8');
        $school->update(['show_all_section_logos_on_login' => true, 'logo_path' => 'schools/1/default-logo.png']);
        app(TenantManager::class)->use($school);
        [, $secondary] = $this->makeTwoSections();
        SchoolSectionBranding::create(['section_id' => $secondary->id, 'logo_path' => 'schools/1/secondary-logo.png']);
        app(TenantManager::class)->forget();

        $response = $this->get("http://{$school->slug}.localhost/login");
        $response->assertOk();
        $response->assertSee('class="lp-logo-lockup">', false);
        $response->assertSee('secondary-logo.png', false);
    }

    public function test_login_page_falls_back_to_a_single_logo_when_the_toggle_is_on_but_no_section_actually_differs(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'secbrand9');
        $school->update(['show_all_section_logos_on_login' => true]);
        app(TenantManager::class)->use($school);
        $this->makeTwoSections(); // neither has its own override
        app(TenantManager::class)->forget();

        $response = $this->get("http://{$school->slug}.localhost/login");
        $response->assertOk();
        $response->assertDontSee('class="lp-logo-lockup">', false);
    }
}
