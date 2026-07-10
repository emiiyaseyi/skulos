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
 * Results entry (user request, built 2026-07-09/2026-07-10): a teacher
 * picks a term/class/subject they teach and enters scores per assessment
 * component in one table. Report cards already read straight from
 * `Score` rows via ResultService, so these tests focus on who can reach
 * which class/subject and that saved rows land correctly.
 */
class ExaminationScreenTest extends TestCase
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
        $subject = Subject::create(['name' => 'Mathematics']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();

        $components = [
            AssessmentComponent::create(['name' => 'First CA', 'max_score' => 20, 'sequence' => 1]),
            AssessmentComponent::create(['name' => 'Second CA', 'max_score' => 20, 'sequence' => 2]),
            AssessmentComponent::create(['name' => 'Exam', 'max_score' => 60, 'sequence' => 3]),
        ];

        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);
        $bode = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade', 'current_class_arm_id' => $classArm->id]);

        app(TenantManager::class)->forget();

        return compact('classArm', 'term', 'subject', 'components', 'ada', 'bode');
    }

    protected function attachTeacher(School $school, User $user, int $classArmId, int $subjectId): void
    {
        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'T1', 'first_name' => 'Tade', 'last_name' => 'Teacher', 'user_id' => $user->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subjectId, 'class_arm_id' => $classArmId]);
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_sees_only_their_assigned_class_and_subject(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examone');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations")
            ->assertOk()
            ->assertSee('JSS 1 A: Mathematics');
    }

    public function test_a_teacher_can_save_scores_for_their_assigned_class_and_subject(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examtwo');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [
                $f['ada']->id => [$first->id => 18, $second->id => 16, $exam->id => 52],
                $f['bode']->id => [$first->id => 10, $second->id => 10, $exam->id => 30],
            ],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(6, Score::count());
        $adaTotal = Score::where('student_id', $f['ada']->id)->sum('score');
        app(TenantManager::class)->forget();
        $this->assertEquals(86, $adaTotal);
    }

    public function test_saving_again_updates_existing_scores_instead_of_duplicating(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examthree');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        $payload = [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$first->id => 10, $second->id => 10, $exam->id => 30]],
        ];
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", $payload);

        $payload['scores'][$f['ada']->id][$first->id] = 20;
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", $payload);

        app(TenantManager::class)->use($school);
        $this->assertSame(3, Score::count());
        $updated = Score::where('student_id', $f['ada']->id)->where('assessment_component_id', $first->id)->first();
        app(TenantManager::class)->forget();
        $this->assertEquals(20, $updated->score);
    }

    public function test_a_teacher_cannot_save_scores_for_a_class_or_subject_they_do_not_teach(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examfour');
        $f = $this->seedAcademics($school);
        // Deliberately no attachTeacher() call: this teacher has no TeachingAssignment at all.

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$f['components'][0]->id => 15]],
        ])->assertForbidden();

        app(TenantManager::class)->use($school);
        $this->assertSame(0, Score::count());
        app(TenantManager::class)->forget();
    }

    public function test_a_score_above_the_components_max_is_rejected_and_nothing_is_saved(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examfive');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [
                $f['ada']->id => [$first->id => 999, $second->id => 10, $exam->id => 30],
            ],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(0, Score::count());
        app(TenantManager::class)->forget();
    }

    public function test_a_school_owner_can_save_scores_without_any_teaching_assignment(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'examsix');
        $f = $this->seedAcademics($school);
        [$first, $second, $exam] = $f['components'];

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations")
            ->assertOk()->assertSee('JSS 1 A: Mathematics');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$first->id => 18, $second->id => 16, $exam->id => 52]],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(3, Score::count());
        app(TenantManager::class)->forget();
    }

    /**
     * Regression test for a 2026-07-10 bug caught only by a live smoke
     * test, not the suite: allowedPairs() compared class_arm_id/subject_id
     * with strict === against the GET query string's (int) cast values,
     * but the pairs themselves were left as whatever type Eloquent handed
     * back, so the match silently failed on a real HTTP request even
     * though the direct save() POST worked fine. Revisiting the page with
     * ?pair=... afterwards must actually show the entry table with the
     * scores prefilled, not fall back to "choose a class and subject".
     */
    public function test_revisiting_with_a_pair_in_the_query_string_prefills_saved_scores(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'exameight');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$first->id => 18, $second->id => 16, $exam->id => 52]],
        ]);

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations?pair={$f['classArm']->id}:{$f['subject']->id}");

        $response->assertOk()
            ->assertSee('Ada Obi')
            ->assertSee('value="18.00"', false)
            ->assertDontSee('Choose a term, class, and subject above');
    }

    public function test_a_teacher_with_no_teaching_assignments_sees_a_helpful_empty_state(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examseven');
        $this->seedAcademics($school);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations")
            ->assertOk()
            ->assertSee('not assigned to teach');
    }
}
