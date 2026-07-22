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
 * Academic Insights and Teacher Workload (Advance plan features, built
 * 2026-07-18 as part of "make all the coming soon under foundation and
 * advance active"). Academic Insights: whole school, subject level
 * ranking and grade distribution, distinct from the Student Performance
 * Dashboard (class/student level). Teacher Workload: real subject/class/
 * student counts per staff member from TeachingAssignment and class
 * teacher/assistant assignment, no Timetable Management dependency
 * (that stays deferred).
 */
class AcademicInsightsAndWorkloadTest extends TestCase
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

    protected function seedAcademics(School $school): array
    {
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $maths = Subject::create(['name' => 'Mathematics']);
        $english = Subject::create(['name' => 'English']);
        $class->subjects()->attach([$maths->id, $english->id], ['is_mandatory' => true, 'is_examinable' => true]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();

        $first = AssessmentComponent::create(['name' => 'First CA', 'max_score' => 40, 'sequence' => 1]);
        $exam = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 60, 'sequence' => 2]);

        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);
        $bola = Student::create(['admission_number' => 'A2', 'first_name' => 'Bola', 'last_name' => 'Coker', 'current_class_arm_id' => $classArm->id]);

        foreach ([[$ada, 35, 55], [$bola, 20, 30]] as [$student, $caScore, $examScore]) {
            Score::create(['student_id' => $student->id, 'subject_id' => $maths->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $first->id, 'score' => $caScore]);
            Score::create(['student_id' => $student->id, 'subject_id' => $maths->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $exam->id, 'score' => $examScore]);
        }

        app(TenantManager::class)->forget();

        return compact('classArm', 'term', 'maths', 'english', 'ada', 'bola');
    }

    public function test_academic_insights_ranks_subjects_and_shows_grade_distribution(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'insightsone');
        $this->seedAcademics($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/academic-insights")
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertSee('90%'); // Ada: 35+55=90
    }

    /** User request 2026-07-22: "Academic Insights page should have the filter and sort option to the data there." */
    public function test_academic_insights_subject_table_has_filter_and_sort_controls(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'insightsfilter');
        $this->seedAcademics($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/academic-insights")
            ->assertOk()
            ->assertSee('Filter by subject')
            ->assertSee('id="ai-subject-sort"', false);
    }

    /** User request 2026-07-19: "should also have another section to view per section or a class only under the term and session." */
    public function test_academic_insights_can_be_scoped_to_one_section_or_one_class(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'insightsthree');
        ['classArm' => $secondaryArm, 'term' => $term] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $primarySection = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $primaryClass = SchoolClass::create(['section_id' => $primarySection->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $science = Subject::create(['name' => 'Basic Science']);
        $primaryClass->subjects()->attach($science->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $primaryArm = ClassArm::create(['school_class_id' => $primaryClass->id]);
        $component = AssessmentComponent::first();
        $chidi = Student::create(['admission_number' => 'P1', 'first_name' => 'Chidi', 'last_name' => 'Eze', 'current_class_arm_id' => $primaryArm->id]);
        Score::create(['student_id' => $chidi->id, 'subject_id' => $science->id, 'class_arm_id' => $primaryArm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 10]);
        $secondarySectionId = $secondaryArm->schoolClass->section_id;
        app(TenantManager::class)->forget();

        // Whole school: both subjects show.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/academic-insights")
            ->assertOk()->assertSee('Mathematics')->assertSee('Basic Science');

        // Scoped to Primary section: only Basic Science shows.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/academic-insights?section_id={$primarySection->id}")
            ->assertOk()->assertSee('Basic Science')->assertDontSee('Mathematics');

        // Scoped to the one secondary class: only Mathematics shows.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/academic-insights?section_id={$secondarySectionId}&class_arm_id={$secondaryArm->id}")
            ->assertOk()->assertSee('Mathematics')->assertDontSee('Basic Science');
    }

    public function test_a_teacher_without_the_blanket_permission_cannot_reach_academic_insights(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('teacher', 'insightstwo');

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/examinations/academic-insights")
            ->assertForbidden();
    }

    public function test_teacher_workload_shows_real_subject_and_class_teacher_counts(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'workloadone');
        ['classArm' => $classArm, 'maths' => $maths, 'english' => $english] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $teacherUser = User::factory()->create();
        $staff = Staff::create(['staff_id' => 'T1', 'first_name' => 'Tade', 'last_name' => 'Teacher', 'user_id' => $teacherUser->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $maths->id, 'class_arm_id' => $classArm->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $english->id, 'class_arm_id' => $classArm->id]);
        $classArm->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/staff-portal/workload")
            ->assertOk()
            ->assertSee('Tade Teacher')
            ->assertSee('Class teacher')
            ->assertSee('2') // subjects taught
            ->assertSee('JSS 1'); // class label shown for the class teacher
    }

    /** User report 2026-07-22: two teachers tying for heaviest load must both show, not just whichever sorted first, and a Lowest Load card must exist too. */
    public function test_teacher_workload_shows_every_teacher_tied_for_heaviest_and_lowest_load(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'workloadthree');
        ['classArm' => $classArm, 'maths' => $maths, 'english' => $english] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $teacherA = Staff::create(['staff_id' => 'T2', 'first_name' => 'Tunde', 'last_name' => 'One']);
        $teacherB = Staff::create(['staff_id' => 'T3', 'first_name' => 'Chika', 'last_name' => 'Two']);
        // Both teach the same class arm (same student headcount), so
        // both tie for the heaviest AND the lowest load, the only
        // students in this school.
        TeachingAssignment::create(['staff_id' => $teacherA->id, 'subject_id' => $maths->id, 'class_arm_id' => $classArm->id]);
        TeachingAssignment::create(['staff_id' => $teacherB->id, 'subject_id' => $english->id, 'class_arm_id' => $classArm->id]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/staff-portal/workload");

        $response->assertOk()
            ->assertSee('Tunde One')
            ->assertSee('Chika Two')
            ->assertSee('Heaviest load')
            ->assertSee('Lowest load')
            ->assertSee('2 teachers tied');
    }

    public function test_teacher_workload_requires_permission(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'workloadtwo');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/staff-portal/workload")
            ->assertForbidden();
    }
}
