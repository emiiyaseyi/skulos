<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\GradingBand;
use App\Models\Tenant\GradingScale;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Grading scales (user request 2026-07-11: "the grading structure i gave
 * you earlier... has not been implemented, this should be a sub section
 * under results"). The model and the real NECO/BECE + WAEC default
 * catalog already existed, config('skulos.default_grading_scales'), just
 * no admin screen at all to actually load or manage one.
 */
class GradingScreenTest extends TestCase
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

    public function test_owner_can_load_the_default_grading_scales(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'gradeone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/grading/apply-defaults")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $names = GradingScale::pluck('name')->all();
        $senior = GradingScale::where('name', 'Senior Secondary Scale (WAEC)')->with('bands')->first();
        app(TenantManager::class)->forget();

        $this->assertCount(3, $names);
        $this->assertContains('Junior Secondary Scale', $names);
        $this->assertContains('Nursery and Primary Scale', $names);
        $this->assertCount(9, $senior->bands, 'WAEC uses the real 9 point scale, A1 to F9');
        $this->assertSame('A1', $senior->bands->firstWhere('min_score', 75)->grade);
        $this->assertSame('F9', $senior->bands->firstWhere('min_score', 0)->grade);
    }

    /**
     * Regression test for a live bug found 2026-07-13: the Grading page
     * listed scales alphabetically by name ("Junior Secondary Scale"
     * before "Nursery and Primary Scale"), not the real pedagogical
     * order, same class of bug already fixed for Section/SchoolClass/
     * Subject ordering. Scales must read Nursery/Primary, then Junior,
     * then Senior, matching config('skulos.default_grading_scales')'s
     * own declared order.
     */
    public function test_default_scales_load_in_pedagogical_order_not_alphabetical(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'gradeeight');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/grading/apply-defaults");

        app(TenantManager::class)->use($school);
        $names = GradingScale::orderBy('sequence')->pluck('name')->all();
        app(TenantManager::class)->forget();

        $this->assertSame([
            'Nursery and Primary Scale',
            'Junior Secondary Scale',
            'Senior Secondary Scale (WAEC)',
        ], $names);
    }

    public function test_loading_defaults_only_tops_up_scales_not_already_present(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'gradetwo');

        app(TenantManager::class)->use($school);
        GradingScale::create(['name' => 'Junior Secondary Scale']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/grading/apply-defaults");

        app(TenantManager::class)->use($school);
        $count = GradingScale::count();
        $juniorCount = GradingScale::where('name', 'Junior Secondary Scale')->count();
        app(TenantManager::class)->forget();

        $this->assertSame(3, $count);
        $this->assertSame(1, $juniorCount, 'the already existing scale must not be duplicated');
    }

    public function test_the_first_scale_created_becomes_the_default_automatically(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'gradethree');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/grading", ['name' => 'My Scale']);

        app(TenantManager::class)->use($school);
        $scale = GradingScale::where('name', 'My Scale')->first();
        app(TenantManager::class)->forget();

        $this->assertTrue($scale->is_default);
    }

    public function test_owner_can_add_edit_and_remove_a_band(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'gradefour');

        app(TenantManager::class)->use($school);
        $scale = GradingScale::create(['name' => 'Custom Scale']);
        $scaleId = $scale->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/grading/{$scaleId}/bands", [
            'min_score' => 70, 'max_score' => 100, 'grade' => 'A', 'remark' => 'Excellent',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $band = GradingBand::where('grading_scale_id', $scaleId)->first();
        $bandId = $band->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/grading/bands/{$bandId}", [
            'min_score' => 75, 'max_score' => 100, 'grade' => 'A', 'remark' => 'Outstanding',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = GradingBand::find($bandId);
        app(TenantManager::class)->forget();
        $this->assertSame('Outstanding', $updated->remark);
        $this->assertEquals(75, $updated->min_score);

        $this->actingAs($owner)->delete("http://{$school->slug}.localhost/examinations/grading/bands/{$bandId}")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $gone = GradingBand::find($bandId);
        app(TenantManager::class)->forget();
        $this->assertNull($gone);
    }

    public function test_a_default_scale_with_scores_cannot_be_removed(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'gradefive');

        app(TenantManager::class)->use($school);
        $scale = GradingScale::create(['name' => 'In Use Scale']);
        $scale->makeDefault();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        \App\Models\Tenant\Score::create([
            'student_id' => \App\Models\Tenant\Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $arm->id])->id,
            'subject_id' => \App\Models\Tenant\Subject::create(['name' => 'Mathematics'])->id,
            'class_arm_id' => $arm->id,
            'assessment_component_id' => \App\Models\Tenant\AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1])->id,
            'term_id' => \App\Models\Tenant\Term::create(['academic_session_id' => \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026 Session'])->id, 'name' => 'First Term', 'sequence' => 1])->id,
            'score' => 80,
        ]);
        $scaleId = $scale->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete("http://{$school->slug}.localhost/examinations/grading/{$scaleId}")
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_a_teacher_without_approve_results_cannot_manage_grading(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'gradesix');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/grading/apply-defaults")
            ->assertForbidden();
    }

    public function test_a_teacher_can_still_view_grading_read_only(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'gradeseven');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations/grading")->assertOk();
    }
}
