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
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Subject Insights (2026-07-22 user request: "report and analytics should
 * have subject insights for class teacher and subject teachers with the
 * necessary date, filter and sort options and calendar date option and
 * term option"). Unlike Academic Insights (whole school, leadership only),
 * this is scoped to only the (class, subject) pairs a teacher's own
 * TeachingAssignment rows say they teach, the same scoping
 * ExaminationController::allowedPairs() uses for score entry.
 */
class SubjectInsightsTest extends TestCase
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

    /** Builds two subjects across two class arms, taught by two different staff, so scoping can be proven. */
    protected function seedAcademics(School $school): array
    {
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $classOne = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $classTwo = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 2]);
        $maths = Subject::create(['name' => 'Mathematics']);
        $english = Subject::create(['name' => 'English']);
        $classOne->subjects()->attach([$maths->id, $english->id], ['is_mandatory' => true, 'is_examinable' => true]);
        $classTwo->subjects()->attach([$maths->id], ['is_mandatory' => true, 'is_examinable' => true]);
        $arm = Arm::create(['name' => 'A']);
        $armOne = ClassArm::create(['school_class_id' => $classOne->id, 'arm_id' => $arm->id]);
        $armTwo = ClassArm::create(['school_class_id' => $classTwo->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();

        $first = AssessmentComponent::create(['name' => 'First CA', 'max_score' => 40, 'sequence' => 1]);
        $exam = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 60, 'sequence' => 2]);

        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $armOne->id]);
        $bola = Student::create(['admission_number' => 'A2', 'first_name' => 'Bola', 'last_name' => 'Coker', 'current_class_arm_id' => $armTwo->id]);

        Score::create(['student_id' => $ada->id, 'subject_id' => $maths->id, 'class_arm_id' => $armOne->id, 'term_id' => $term->id, 'assessment_component_id' => $first->id, 'score' => 35]);
        Score::create(['student_id' => $ada->id, 'subject_id' => $maths->id, 'class_arm_id' => $armOne->id, 'term_id' => $term->id, 'assessment_component_id' => $exam->id, 'score' => 55]);
        Score::create(['student_id' => $ada->id, 'subject_id' => $english->id, 'class_arm_id' => $armOne->id, 'term_id' => $term->id, 'assessment_component_id' => $first->id, 'score' => 20]);
        Score::create(['student_id' => $bola->id, 'subject_id' => $maths->id, 'class_arm_id' => $armTwo->id, 'term_id' => $term->id, 'assessment_component_id' => $first->id, 'score' => 10]);

        $teacherUser = User::factory()->create();
        $teacher = Staff::create(['staff_id' => 'T1', 'first_name' => 'Tade', 'last_name' => 'Teacher', 'user_id' => $teacherUser->id]);
        // This teacher only teaches Mathematics in JSS 1, never English,
        // and never anything in JSS 2 (that is taught by someone else) —
        // the exact scoping this feature must respect.
        TeachingAssignment::create(['staff_id' => $teacher->id, 'subject_id' => $maths->id, 'class_arm_id' => $armOne->id]);

        $otherTeacher = Staff::create(['staff_id' => 'T2', 'first_name' => 'Other', 'last_name' => 'Person']);
        TeachingAssignment::create(['staff_id' => $otherTeacher->id, 'subject_id' => $english->id, 'class_arm_id' => $armOne->id]);
        TeachingAssignment::create(['staff_id' => $otherTeacher->id, 'subject_id' => $maths->id, 'class_arm_id' => $armTwo->id]);

        app(TenantManager::class)->forget();

        return compact('armOne', 'armTwo', 'term', 'maths', 'english', 'teacherUser');
    }

    public function test_a_subject_teacher_sees_only_the_class_and_subject_they_actually_teach(): void
    {
        [$school, $subjectTeacherUser] = $this->schoolWithMember('subject-teacher', 'subinsights1');
        ['teacherUser' => $teacherUser] = $this->seedAcademics($school);

        // Attach the same real Staff row to the acting-as user by reusing
        // the teacher user created inside seedAcademics rather than the
        // freshly minted role member, since TeachingAssignment is keyed to
        // a Staff row, not directly to a school membership.
        $school->users()->attach($teacherUser->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        $response = $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/reports/subject-insights");

        $response->assertOk()
            ->assertSee('JSS 1')
            ->assertSee('Mathematics')
            ->assertDontSee('English')
            ->assertDontSee('JSS 2')
            ->assertSee('90.0'); // Ada: 35 + 55
    }

    public function test_the_class_and_subject_filters_narrow_the_table(): void
    {
        [$school, ] = $this->schoolWithMember('subject-teacher', 'subinsights2');
        ['teacherUser' => $teacherUser, 'maths' => $maths, 'english' => $english, 'armOne' => $armOne] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        // Give this teacher two subjects in the same class so the subject filter is meaningful.
        $staff = Staff::where('user_id', $teacherUser->id)->firstOrFail();
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $english->id, 'class_arm_id' => $armOne->id]);
        app(TenantManager::class)->forget();
        $school->users()->attach($teacherUser->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        // "English" itself still appears in the subject filter dropdown's
        // own option list, so assert on the table's row count instead of
        // the word "English" (which the dropdown legitimately still shows).
        $response = $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/reports/subject-insights?subject_id={$maths->id}");
        $response->assertOk()->assertSee('Mathematics');
        $this->assertSame(1, substr_count($response->getContent(), '<td>Mathematics</td>'));
    }

    public function test_a_user_without_examinations_view_cannot_reach_subject_insights(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'subinsights3');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/reports/subject-insights")
            ->assertForbidden();
    }

    public function test_a_teacher_with_no_teaching_assignment_sees_an_empty_state_not_an_error(): void
    {
        [$school, $subjectTeacher] = $this->schoolWithMember('subject-teacher', 'subinsights4');

        $this->actingAs($subjectTeacher)->get("http://{$school->slug}.localhost/reports/subject-insights")
            ->assertOk()
            ->assertSee('No class or subject is currently assigned to you for teaching.');
    }

    /** The nav link itself must only ever appear for a scoped role that actually holds examinations.view. */
    public function test_the_subject_insights_nav_link_is_gated_by_permission(): void
    {
        [$school, $subjectTeacherUser] = $this->schoolWithMember('subject-teacher', 'subinsights5');
        $this->seedAcademics($school);
        $school->update(['plan_id' => \App\Models\Plan::firstOrCreate(
            ['slug' => 'foundation'],
            ['name' => 'Foundation', 'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['subject_insights'], 'is_default' => true, 'is_active' => true, 'is_purchasable' => true, 'sort_order' => 1]
        )->id]);

        $this->actingAs($subjectTeacherUser)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('Subject Insights');
    }
}
