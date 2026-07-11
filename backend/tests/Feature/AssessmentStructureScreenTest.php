<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Score;
use App\Models\Tenant\Section;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Assessment structure (user request 2026-07-10): the school-wide "Test /
 * Continuous Assessment" and "Examinations" component list, previously a
 * model with no admin screen at all. Gated behind examinations.approve_
 * results, a stricter permission than the plain create/update a rank and
 * file teacher holds, since this redefines the whole school's format.
 */
class AssessmentStructureScreenTest extends TestCase
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

    public function test_owner_can_add_ca_and_exam_components(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'structone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/structure", [
            'name' => 'First CA', 'type' => 'ca', 'max_score' => 20,
        ])->assertRedirect();
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/structure", [
            'name' => 'Exam', 'type' => 'exam', 'max_score' => 80,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $ca = AssessmentComponent::where('name', 'First CA')->first();
        $exam = AssessmentComponent::where('name', 'Exam')->first();
        $total = AssessmentComponent::totalPossible();
        app(TenantManager::class)->forget();

        $this->assertSame('ca', $ca->type);
        $this->assertSame('exam', $exam->type);
        $this->assertSame(100.0, $total);
    }

    /**
     * One-click default structure (guided setup wizard, user request
     * 2026-07-11): two CAs plus one exam summing to 100, only ever loaded
     * onto an empty structure so it never overwrites a school's own.
     */
    public function test_owner_can_load_the_default_structure(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'structeleven');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/structure/apply-defaults")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $names = AssessmentComponent::orderBy('sequence')->pluck('name')->all();
        $total = AssessmentComponent::totalPossible();
        app(TenantManager::class)->forget();

        $this->assertSame(['First CA', 'Second CA', 'Exam'], $names);
        $this->assertSame(100.0, $total);
    }

    public function test_loading_defaults_does_not_overwrite_an_existing_structure(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'structtwelve');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/structure", [
            'name' => 'Custom CA', 'type' => 'ca', 'max_score' => 30,
        ]);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/structure/apply-defaults")
            ->assertRedirect()->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $count = AssessmentComponent::count();
        app(TenantManager::class)->forget();
        $this->assertSame(1, $count, 'the default loader must not touch an already-defined structure');
    }

    public function test_a_teacher_without_approve_results_cannot_load_defaults(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'structthirteen');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/structure/apply-defaults")
            ->assertForbidden();
    }

    public function test_the_structure_page_groups_components_by_type(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'structtwo');

        app(TenantManager::class)->use($school);
        AssessmentComponent::create(['name' => 'First CA', 'type' => 'ca', 'max_score' => 20, 'sequence' => 1]);
        AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 80, 'sequence' => 2]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/structure")
            ->assertOk()
            ->assertSee('Test / Continuous Assessment', false)
            ->assertSee('Examinations')
            ->assertSee('First CA')
            ->assertSee('Exam');
    }

    public function test_a_component_name_must_be_unique(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'structthree');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/structure", [
            'name' => 'First CA', 'type' => 'ca', 'max_score' => 20,
        ]);
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/structure", [
            'name' => 'First CA', 'type' => 'ca', 'max_score' => 30,
        ])->assertSessionHasErrors('name');

        app(TenantManager::class)->use($school);
        $count = AssessmentComponent::where('name', 'First CA')->count();
        app(TenantManager::class)->forget();
        $this->assertSame(1, $count);
    }

    public function test_a_component_can_be_edited(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'structfour');

        app(TenantManager::class)->use($school);
        $component = AssessmentComponent::create(['name' => 'First CA', 'type' => 'ca', 'max_score' => 20, 'sequence' => 1]);
        $componentId = $component->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/structure/{$componentId}", [
            'name' => 'First Continuous Assessment', 'max_score' => 25,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $component = AssessmentComponent::find($componentId);
        app(TenantManager::class)->forget();
        $this->assertSame('First Continuous Assessment', $component->name);
        $this->assertEquals(25, $component->max_score);
    }

    public function test_a_component_with_no_scores_can_be_deleted(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'structfive');

        app(TenantManager::class)->use($school);
        $component = AssessmentComponent::create(['name' => 'First CA', 'type' => 'ca', 'max_score' => 20, 'sequence' => 1]);
        $componentId = $component->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete("http://{$school->slug}.localhost/examinations/structure/{$componentId}")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $exists = AssessmentComponent::find($componentId);
        app(TenantManager::class)->forget();
        $this->assertNull($exists);
    }

    /**
     * The FK from scores to assessment_components cascades on delete, so
     * deleting a component with scores would silently destroy that data.
     * This must be refused, not "succeed" and quietly wipe scores.
     */
    public function test_a_component_with_scores_cannot_be_deleted(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'structsix');

        app(TenantManager::class)->use($school);
        $component = AssessmentComponent::create(['name' => 'First CA', 'type' => 'ca', 'max_score' => 20, 'sequence' => 1]);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        Score::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id,
            'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 15,
        ]);
        $componentId = $component->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete("http://{$school->slug}.localhost/examinations/structure/{$componentId}")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $stillExists = AssessmentComponent::find($componentId);
        $scoreStillExists = Score::where('assessment_component_id', $componentId)->exists();
        app(TenantManager::class)->forget();
        $this->assertNotNull($stillExists, 'the component must not be deleted while scores reference it');
        $this->assertTrue($scoreStillExists);
    }

    public function test_a_teacher_without_approve_results_cannot_manage_the_structure(): void
    {
        // subject-teacher has examinations.create/update but not approve_results.
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'structseven');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/structure", [
            'name' => 'First CA', 'type' => 'ca', 'max_score' => 20,
        ])->assertForbidden();

        app(TenantManager::class)->use($school);
        $exists = AssessmentComponent::where('name', 'First CA')->exists();
        app(TenantManager::class)->forget();
        $this->assertFalse($exists);
    }

    public function test_a_teacher_can_still_view_the_structure_read_only(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'structeight');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations/structure")
            ->assertOk()->assertDontSee('Remove');
    }
}
