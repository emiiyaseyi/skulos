<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
use App\Models\Tenant\CbtEssayGrade;
use App\Models\Tenant\CbtGrader;
use App\Models\Tenant\CbtGraderAssignment;
use App\Models\Tenant\CbtResponse;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\CbtSession;
use App\Models\User;
use App\Services\Cbt\GradingDistributionService;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Grading: who is assigned what (all/split/random/manual), blind
 * marking, and how a layer-2 review reconciles against layer 1.
 */
class CbtGradingTest extends TestCase
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

    protected function member(School $school, string $roleSlug, ?string $name = null): User
    {
        $user = User::factory()->create($name ? ['name' => $name] : []);
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    /** A school with one essay assessment and $studentCount submitted attempts. */
    protected function scenario(string $slug, int $studentCount = 4): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $owner = $this->member($school, 'school-owner');
        $students = collect(range(1, $studentCount))
            ->map(fn ($i) => $this->member($school, 'student', "Student {$i}"));

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $assessment = CbtAssessment::create([
            'title' => 'Essay Paper', 'duration_minutes' => 30,
            'security_profile_id' => $basic->id, 'audience' => 'student',
        ]);
        $essay = $this->makeEssayQuestion('Explain the water cycle.', 10);
        CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $essay->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        foreach ($students as $student) {
            $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));
            app(TenantManager::class)->use($school);
            $session = CbtSession::where('takeable_id', $student->id)->first();
            app(TenantManager::class)->forget();
            $this->actingAs($student)->post(route('school.cbt.take.answer', ['school' => $school->slug, 'sessionId' => $session->id]), [
                'question_id' => $essay->id, 'answer' => 'Evaporation, condensation, precipitation.',
            ]);
            $this->actingAs($student)->post(route('school.cbt.take.submit', ['school' => $school->slug, 'sessionId' => $session->id]));
        }

        return [$school, $owner, $students, $assessment, $essay];
    }

    public function test_even_split_deals_attempts_round_robin_between_graders(): void
    {
        [$school, $owner, $students, $assessment] = $this->scenario('grdone', 4);

        $graderA = $this->member($school, 'teacher', 'Grader A');
        $graderB = $this->member($school, 'teacher', 'Grader B');

        app(TenantManager::class)->use($school);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $graderA->id, 'grade_layer' => 1]);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $graderB->id, 'grade_layer' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.people.grading-mode', ['school' => $school->slug, 'assessmentId' => $assessment->id]), [
            'mode' => 'split', 'cap' => 0,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(4, CbtGraderAssignment::count());
        // Four attempts, two graders, so exactly two each.
        $this->assertSame(2, CbtGraderAssignment::where('grader_user_id', $graderA->id)->count());
        $this->assertSame(2, CbtGraderAssignment::where('grader_user_id', $graderB->id)->count());
        app(TenantManager::class)->forget();
    }

    public function test_redistributing_never_moves_an_already_assigned_attempt(): void
    {
        [$school, $owner, $students, $assessment] = $this->scenario('grdtwo', 4);

        $graderA = $this->member($school, 'teacher', 'Grader A');
        app(TenantManager::class)->use($school);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $graderA->id, 'grade_layer' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.people.grading-mode', ['school' => $school->slug, 'assessmentId' => $assessment->id]), ['mode' => 'split']);

        app(TenantManager::class)->use($school);
        $before = CbtGraderAssignment::orderBy('session_id')->pluck('grader_user_id', 'session_id')->all();
        app(TenantManager::class)->forget();

        // A second grader joins and the mode is re-saved. Existing
        // assignments must stay put — re-dealing them would silently
        // move a half-marked attempt to a different person.
        $graderB = $this->member($school, 'teacher', 'Grader B');
        app(TenantManager::class)->use($school);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $graderB->id, 'grade_layer' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.people.grading-mode', ['school' => $school->slug, 'assessmentId' => $assessment->id]), ['mode' => 'split']);

        app(TenantManager::class)->use($school);
        $after = CbtGraderAssignment::orderBy('session_id')->pluck('grader_user_id', 'session_id')->all();
        $this->assertSame($before, $after);
        app(TenantManager::class)->forget();
    }

    public function test_all_mode_creates_no_assignments_and_every_grader_sees_everything(): void
    {
        [$school, $owner, $students, $assessment] = $this->scenario('grdthree', 3);

        $graderA = $this->member($school, 'teacher');
        app(TenantManager::class)->use($school);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $graderA->id, 'grade_layer' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.people.grading-mode', ['school' => $school->slug, 'assessmentId' => $assessment->id]), ['mode' => 'all']);

        app(TenantManager::class)->use($school);
        $this->assertSame(0, CbtGraderAssignment::count());
        $visible = app(GradingDistributionService::class)
            ->sessionsFor(CbtAssessment::find($assessment->id), $graderA->id, 1)->count();
        $this->assertSame(3, $visible);
        app(TenantManager::class)->forget();
    }

    public function test_a_layer_two_reviewer_sees_every_attempt_even_under_split(): void
    {
        [$school, $owner, $students, $assessment] = $this->scenario('grdfour', 4);

        $graderA = $this->member($school, 'teacher');
        $reviewer = $this->member($school, 'teacher');
        app(TenantManager::class)->use($school);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $graderA->id, 'grade_layer' => 1]);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $reviewer->id, 'grade_layer' => 2]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.people.grading-mode', ['school' => $school->slug, 'assessmentId' => $assessment->id]), ['mode' => 'split']);

        app(TenantManager::class)->use($school);
        $service = app(GradingDistributionService::class);
        $fresh = CbtAssessment::find($assessment->id);
        // Layer 1 only sees their own dealt pile...
        $this->assertSame(4, $service->sessionsFor($fresh, $graderA->id, 1)->count());
        // ...but a moderator must see everything to moderate at all.
        $this->assertSame(4, $service->sessionsFor($fresh, $reviewer->id, 2)->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_blind_grader_never_receives_the_takers_name(): void
    {
        [$school, $owner, $students, $assessment] = $this->scenario('grdfive', 1);

        $grader = $this->member($school, 'teacher');
        app(TenantManager::class)->use($school);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $grader->id, 'grade_layer' => 1, 'anonymous' => true]);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $response = $this->actingAs($grader)
            ->get(route('school.cbt.grader.show', ['school' => $school->slug, 'sessionId' => $session->id]));

        $response->assertOk()
            ->assertSee('Candidate #'.$session->id)
            // The real name must not be in the HTML at all — hiding it
            // in CSS would still ship it to the browser.
            ->assertDontSee('Student 1');
    }

    public function test_a_named_grader_does_see_the_taker(): void
    {
        [$school, $owner, $students, $assessment] = $this->scenario('grdsix', 1);

        $grader = $this->member($school, 'teacher');
        app(TenantManager::class)->use($school);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $grader->id, 'grade_layer' => 1, 'anonymous' => false]);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($grader)
            ->get(route('school.cbt.grader.show', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertOk()->assertSee('Student 1');
    }

    public function test_a_layer_two_review_overrides_layer_one_and_keeps_the_history(): void
    {
        [$school, $owner, $students, $assessment] = $this->scenario('grdseven', 1);

        $first = $this->member($school, 'teacher');
        $reviewer = $this->member($school, 'teacher');
        app(TenantManager::class)->use($school);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $first->id, 'grade_layer' => 1]);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $reviewer->id, 'grade_layer' => 2]);
        $session = CbtSession::first();
        $responseId = CbtResponse::where('session_id', $session->id)->value('id');
        app(TenantManager::class)->forget();

        $this->actingAs($first)->post(route('school.cbt.grader.grade', ['school' => $school->slug, 'responseId' => $responseId]), [
            'score' => 4, 'feedback' => 'Missed runoff.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(4.0, (float) CbtResponse::find($responseId)->score_awarded);
        // Whole session finalises once nothing is ungraded.
        $this->assertSame(CbtSession::STATUS_GRADED, $session->fresh()->status);
        app(TenantManager::class)->forget();

        $this->actingAs($reviewer)->post(route('school.cbt.grader.grade', ['school' => $school->slug, 'responseId' => $responseId]), [
            'score' => 7, 'feedback' => 'Runoff was implied; raising the mark.',
        ]);

        app(TenantManager::class)->use($school);
        $this->assertSame(7.0, (float) CbtResponse::find($responseId)->score_awarded);
        // Both marks survive — the override never erases layer 1.
        $this->assertSame(2, CbtEssayGrade::where('response_id', $responseId)->count());
        $this->assertSame(4.0, (float) CbtEssayGrade::where('grade_layer', 1)->value('score'));
        app(TenantManager::class)->forget();

        // The first grader saving again must NOT beat the review just
        // because it is chronologically last.
        $this->actingAs($first)->post(route('school.cbt.grader.grade', ['school' => $school->slug, 'responseId' => $responseId]), [
            'score' => 2,
        ]);

        app(TenantManager::class)->use($school);
        $this->assertSame(7.0, (float) CbtResponse::find($responseId)->score_awarded,
            'a layer-2 review must win on authority, not on save ordering');
        app(TenantManager::class)->forget();
    }

    public function test_a_mark_above_the_questions_maximum_is_rejected(): void
    {
        [$school, $owner, $students, $assessment] = $this->scenario('grdeight', 1);

        $grader = $this->member($school, 'teacher');
        app(TenantManager::class)->use($school);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $grader->id, 'grade_layer' => 1]);
        $responseId = CbtResponse::first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($grader)->post(route('school.cbt.grader.grade', ['school' => $school->slug, 'responseId' => $responseId]), [
            'score' => 99,
        ])->assertStatus(422);
    }

    public function test_only_a_reviewer_can_void_or_reset_an_attempt(): void
    {
        [$school, $owner, $students, $assessment] = $this->scenario('grdnine', 1);

        $first = $this->member($school, 'teacher');
        app(TenantManager::class)->use($school);
        CbtGrader::create(['assessment_id' => $assessment->id, 'user_id' => $first->id, 'grade_layer' => 1]);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($first)->post(route('school.cbt.grader.void', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertForbidden();
        $this->actingAs($first)->post(route('school.cbt.grader.reset', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertForbidden();
    }

    public function test_someone_who_is_not_a_grader_cannot_open_an_attempt(): void
    {
        [$school, $owner, $students, $assessment] = $this->scenario('grdten', 1);

        $teacher = $this->member($school, 'teacher'); // has .grade, but is not assigned
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)
            ->get(route('school.cbt.grader.show', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertForbidden();
    }
}
