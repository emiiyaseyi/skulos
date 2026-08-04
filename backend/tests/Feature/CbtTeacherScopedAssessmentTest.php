<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\CbtSession;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Score;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
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
 * Two user directives, 2026-08-02, tested together since both build on
 * the same TeachingAssignment-scoped fixture:
 *
 * 1. "class teacher and subject teacher can set assessment for the
 *    classes assigned to them" — CbtAssessmentController's new
 *    hasBlanketAccess()/allowedSubjectIds()/allowedClassArmIds()/
 *    authorizeOwnership().
 * 2. "class teacher and subject teacher can decide that the assessment
 *    should be used for a continuous assessment or examination" —
 *    GradebookSyncService, exercised through the real HTTP take/grade
 *    flow rather than called directly, so the two "just became graded"
 *    trigger points (CbtSession::finalizeIfFullyGraded()) are proven
 *    for real, not assumed.
 */
class CbtTeacherScopedAssessmentTest extends TestCase
{
    use \Tests\Concerns\MakesCbtQuestions, RefreshDatabase;

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

    /**
     * A school with: two subjects (Mathematics, English), two class
     * arms (JSS1A, JSS1B), a subject-teacher scoped ONLY to Mathematics
     * + JSS1A (via a real TeachingAssignment), and a blanket
     * school-owner. Both are real logins on the 'web' guard.
     */
    protected function fixture(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', Module::STATE_ENABLED);

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $teacherUser = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'subject-teacher');
        $school->users()->attach($teacherUser->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS1', 'sequence' => 1]);
        $armA = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $armB = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $maths = Subject::create(['name' => 'Mathematics']);
        $english = Subject::create(['name' => 'English']);
        $academicSession = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $term = Term::create(['academic_session_id' => $academicSession->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();

        $staff = Staff::create(['staff_id' => 'T1', 'first_name' => 'Tade', 'last_name' => 'Teacher', 'user_id' => $teacherUser->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $maths->id, 'class_arm_id' => $armA->id]);

        $ca1 = AssessmentComponent::create(['name' => 'First CA', 'type' => 'ca', 'max_score' => 20, 'sequence' => 1]);

        $student = Student::create([
            'admission_number' => 'STU/0001', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'current_class_arm_id' => $armA->id,
        ]);

        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();

        app(TenantManager::class)->forget();

        return compact('school', 'owner', 'teacherUser', 'staff', 'maths', 'english', 'armA', 'armB', 'term', 'ca1', 'student', 'basic');
    }

    public function test_a_scoped_teacher_cannot_create_an_assessment_for_a_subject_they_do_not_teach(): void
    {
        $f = $this->fixture('scope1');

        $response = $this->actingAs($f['teacherUser'])->post(
            route('school.cbt.assessments.store', ['school' => $f['school']->slug]),
            ['title' => 'English Quiz', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id, 'audience' => 'student', 'subject_id' => $f['english']->id]
        );

        $response->assertSessionHasErrors('subject_id');
    }

    public function test_a_scoped_teacher_can_create_an_assessment_for_their_own_subject(): void
    {
        $f = $this->fixture('scope2');

        $this->actingAs($f['teacherUser'])->post(
            route('school.cbt.assessments.store', ['school' => $f['school']->slug]),
            ['title' => 'Maths Quiz', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id, 'audience' => 'student', 'subject_id' => $f['maths']->id]
        )->assertRedirect();

        app(TenantManager::class)->use($f['school']);
        $assessment = CbtAssessment::where('title', 'Maths Quiz')->first();
        $this->assertNotNull($assessment);
        $this->assertSame($f['staff']->id, $assessment->created_by_staff_id);
        app(TenantManager::class)->forget();
    }

    public function test_a_scoped_teacher_cannot_target_a_class_arm_they_do_not_teach(): void
    {
        $f = $this->fixture('scope3');

        app(TenantManager::class)->use($f['school']);
        $assessment = CbtAssessment::forceCreate([
            'title' => 'Maths Quiz', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id,
            'audience' => 'student', 'subject_id' => $f['maths']->id, 'created_by_staff_id' => $f['staff']->id,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($f['teacherUser'])->post(
            route('school.cbt.assessments.update', ['school' => $f['school']->slug, 'assessmentId' => $assessment->id]),
            [
                'title' => 'Maths Quiz', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id,
                'audience' => 'student', 'subject_id' => $f['maths']->id,
                'class_arm_ids' => [$f['armA']->id, $f['armB']->id], // armB is NOT theirs
            ]
        )->assertRedirect();

        app(TenantManager::class)->use($f['school']);
        $armIds = $assessment->fresh()->classArms->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$f['armA']->id], $armIds, 'armB must be silently dropped, not synced');
        app(TenantManager::class)->forget();
    }

    public function test_a_scoped_teacher_cannot_edit_another_teachers_assessment(): void
    {
        $f = $this->fixture('scope4');

        app(TenantManager::class)->use($f['school']);
        $otherStaffUser = User::factory()->create();
        $otherStaff = Staff::create(['staff_id' => 'T2', 'first_name' => 'Bola', 'last_name' => 'Other', 'user_id' => $otherStaffUser->id]);
        $assessment = CbtAssessment::forceCreate([
            'title' => 'Not mine', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id,
            'audience' => 'student', 'created_by_staff_id' => $otherStaff->id,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($f['teacherUser'])
            ->get(route('school.cbt.assessments.edit', ['school' => $f['school']->slug, 'assessmentId' => $assessment->id]))
            ->assertForbidden();
    }

    public function test_a_scoped_teachers_index_only_shows_their_own_assessments(): void
    {
        $f = $this->fixture('scope5');

        app(TenantManager::class)->use($f['school']);
        CbtAssessment::forceCreate(['title' => 'Mine', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id, 'audience' => 'student', 'created_by_staff_id' => $f['staff']->id]);
        CbtAssessment::forceCreate(['title' => 'Someone elses', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id, 'audience' => 'student', 'created_by_staff_id' => null]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($f['teacherUser'])
            ->get(route('school.cbt.assessments.index', ['school' => $f['school']->slug]))
            ->assertOk();
        $response->assertSee('Mine');
        $response->assertDontSee('Someone elses');
    }

    public function test_a_blanket_role_is_unrestricted(): void
    {
        $f = $this->fixture('scope6');

        $this->actingAs($f['owner'])->post(
            route('school.cbt.assessments.store', ['school' => $f['school']->slug]),
            ['title' => 'English Quiz', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id, 'audience' => 'student', 'subject_id' => $f['english']->id]
        )->assertRedirect();
    }

    public function test_only_a_class_assessment_can_link_a_gradebook_component(): void
    {
        $f = $this->fixture('gb1');

        $this->actingAs($f['owner'])->post(
            route('school.cbt.assessments.store', ['school' => $f['school']->slug]),
            [
                'title' => 'Staff Quiz', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id,
                'audience' => 'staff', 'assessment_component_id' => $f['ca1']->id,
            ]
        )->assertStatus(422);
    }

    public function test_linking_requires_subject_and_term(): void
    {
        $f = $this->fixture('gb2');

        $this->actingAs($f['owner'])->post(
            route('school.cbt.assessments.store', ['school' => $f['school']->slug]),
            [
                'title' => 'Unscoped Quiz', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id,
                'audience' => 'student', 'assessment_component_id' => $f['ca1']->id,
            ]
        )->assertStatus(422);
    }

    public function test_auto_mode_writes_a_scaled_score_the_moment_grading_finishes_and_overwrites_a_manual_one(): void
    {
        $f = $this->fixture('gb3');

        app(TenantManager::class)->use($f['school']);
        // A pre-existing manual score, which auto-sync must overwrite.
        Score::create([
            'student_id' => $f['student']->id, 'subject_id' => $f['maths']->id, 'class_arm_id' => $f['armA']->id,
            'term_id' => $f['term']->id, 'assessment_component_id' => $f['ca1']->id, 'score' => 5,
        ]);
        $question = $this->makeChoiceQuestion('2 + 2?', ['3', '4', '5'], ['4'], points: 10);
        $assessment = CbtAssessment::forceCreate([
            'title' => 'Maths CA1', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id,
            'audience' => 'student', 'subject_id' => $f['maths']->id, 'term_id' => $f['term']->id,
            'assessment_component_id' => $f['ca1']->id, 'gradebook_sync_mode' => 'auto',
        ]);
        CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $question->id, 'sequence' => 0]);
        $correctAnswerId = $this->answerIdFor($question, '4');
        app(TenantManager::class)->forget();

        $studentUser = $this->studentUserFor($f);
        $this->actingAs($studentUser)
            ->post(route('school.cbt.take.start', ['school' => $f['school']->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($f['school']);
        $session = CbtSession::where('assessment_id', $assessment->id)->first();
        app(TenantManager::class)->forget();

        $this->actingAs($studentUser)->postJson(
            route('school.cbt.take.answer', ['school' => $f['school']->slug, 'sessionId' => $session->id]),
            ['question_id' => $question->id, 'answer' => (string) $correctAnswerId]
        );
        $this->actingAs($studentUser)->post(route('school.cbt.take.submit', ['school' => $f['school']->slug, 'sessionId' => $session->id]));

        app(TenantManager::class)->use($f['school']);
        // 10/10 on the CBT paper, scaled to the component's own 20 max.
        $score = Score::where('student_id', $f['student']->id)->where('assessment_component_id', $f['ca1']->id)->first();
        $this->assertNotNull($score);
        $this->assertEquals(20.0, (float) $score->score, 'a full-marks paper should scale to the component\'s own full max_score');
        $this->assertNotNull(CbtSession::find($session->id)->gradebook_synced_at);
        app(TenantManager::class)->forget();
    }

    public function test_manual_mode_leaves_the_score_unsynced_until_explicitly_pushed(): void
    {
        $f = $this->fixture('gb4');

        app(TenantManager::class)->use($f['school']);
        $question = $this->makeChoiceQuestion('2 + 2?', ['3', '4', '5'], ['4'], points: 10);
        $assessment = CbtAssessment::forceCreate([
            'title' => 'Maths CA1', 'duration_minutes' => 30, 'security_profile_id' => $f['basic']->id,
            'audience' => 'student', 'subject_id' => $f['maths']->id, 'term_id' => $f['term']->id,
            'assessment_component_id' => $f['ca1']->id, 'gradebook_sync_mode' => 'manual',
            'created_by_staff_id' => $f['staff']->id,
        ]);
        CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $question->id, 'sequence' => 0]);
        $correctAnswerId = $this->answerIdFor($question, '4');
        app(TenantManager::class)->forget();

        $studentUser = $this->studentUserFor($f);
        $this->actingAs($studentUser)->post(route('school.cbt.take.start', ['school' => $f['school']->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($f['school']);
        $session = CbtSession::where('assessment_id', $assessment->id)->first();
        app(TenantManager::class)->forget();

        $this->actingAs($studentUser)->postJson(
            route('school.cbt.take.answer', ['school' => $f['school']->slug, 'sessionId' => $session->id]),
            ['question_id' => $question->id, 'answer' => (string) $correctAnswerId]
        );
        $this->actingAs($studentUser)->post(route('school.cbt.take.submit', ['school' => $f['school']->slug, 'sessionId' => $session->id]));

        app(TenantManager::class)->use($f['school']);
        $this->assertNull(Score::where('student_id', $f['student']->id)->where('assessment_component_id', $f['ca1']->id)->first(), 'manual mode must not sync automatically');
        app(TenantManager::class)->forget();

        // Explicit push, as the teacher who created it.
        $this->actingAs($f['teacherUser'])->post(route('school.cbt.hub.push-gradebook', [
            'school' => $f['school']->slug, 'assessmentId' => $assessment->id, 'sessionId' => $session->id,
        ]))->assertRedirect();

        app(TenantManager::class)->use($f['school']);
        $score = Score::where('student_id', $f['student']->id)->where('assessment_component_id', $f['ca1']->id)->first();
        $this->assertNotNull($score);
        $this->assertEquals(20.0, (float) $score->score);
        app(TenantManager::class)->forget();
    }

    /** Students aren't school-scoped users the fixture already tracks a login for — provisions one on demand, matching the existing account the Student row's own user_id (if any) or a fresh one attached with the student role. */
    protected function studentUserFor(array $f): User
    {
        app(TenantManager::class)->use($f['school']);
        $student = Student::find($f['student']->id);
        if ($student->user_id) {
            $userId = $student->user_id;
            app(TenantManager::class)->forget();

            return User::find($userId);
        }
        app(TenantManager::class)->forget();

        $studentUser = User::factory()->create();
        $studentRole = $this->rbac->cloneTemplateRole($f['school'], 'student');
        $f['school']->users()->attach($studentUser->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($f['school']);
        Student::where('id', $f['student']->id)->update(['user_id' => $studentUser->id]);
        app(TenantManager::class)->forget();

        return $studentUser;
    }
}
