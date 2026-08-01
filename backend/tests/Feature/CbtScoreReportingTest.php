<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
use App\Models\Tenant\CbtSchedule;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\CbtSession;
use App\Models\User;
use App\Services\Cbt\ScoreReportingService;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The three reporting screens, the per-question pill states, and the
 * grade method that decides which of several attempts actually counts.
 */
class CbtScoreReportingTest extends TestCase
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
     * @param  bool  $withEssay  an essay keeps the attempt ungraded until a
     *                           human marks it, so total_score stays null and
     *                           there is deliberately no final grade yet. Tests
     *                           about grade METHOD need a purely objective paper.
     */
    protected function scenario(string $slug, bool $withEssay = true): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $student = User::factory()->create(['name' => 'Score Student']);
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $assessment = CbtAssessment::create([
            'title' => 'Scored Paper', 'duration_minutes' => 30,
            'security_profile_id' => $basic->id, 'audience' => 'student', 'max_attempts' => 0,
        ]);
        $mcq = $this->makeChoiceQuestion('2+2?', ['3', '4', '5'], ['4'], 10);
        CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $mcq->id, 'sequence' => 0]);

        $essay = null;
        if ($withEssay) {
            $essay = $this->makeEssayQuestion('Explain.', 10);
            CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $essay->id, 'sequence' => 1]);
        }
        app(TenantManager::class)->forget();

        return [$school, $student, $owner, $assessment, $mcq, $essay];
    }

    /** Runs one full attempt, optionally answering the multiple choice correctly. */
    protected function attempt($student, $school, $assessment, $mcq, bool $correct): CbtSession
    {
        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::where('status', CbtSession::STATUS_IN_PROGRESS)->latest('id')->first();
        $answerId = $mcq->answers->firstWhere('answer_text', $correct ? '4' : '3')->id;
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.answer', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'question_id' => $mcq->id, 'answer' => $answerId,
        ]);
        $this->actingAs($student)->post(route('school.cbt.take.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        return $session;
    }

    public function test_the_three_reporting_screens_render(): void
    {
        [$school, $student, $owner, $assessment, $mcq] = $this->scenario('scrone');
        $this->attempt($student, $school, $assessment, $mcq, true);

        $this->actingAs($owner)->get(route('school.cbt.scores.index', ['school' => $school->slug, 'assessmentId' => $assessment->id]))
            ->assertOk()->assertSee('Score Student');
        $this->actingAs($owner)->get(route('school.cbt.scores.results', ['school' => $school->slug]))
            ->assertOk()->assertSee('Scored Paper');
        $this->actingAs($owner)->get(route('school.cbt.scores.matrix', ['school' => $school->slug]))
            ->assertOk()->assertSee('Score Student');
    }

    public function test_a_student_cannot_reach_the_reporting_screens(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('scrtwo');

        $this->actingAs($student)->get(route('school.cbt.scores.results', ['school' => $school->slug]))->assertForbidden();
        $this->actingAs($student)->get(route('school.cbt.scores.matrix', ['school' => $school->slug]))->assertForbidden();
        $this->actingAs($student)->get(route('school.cbt.scores.index', ['school' => $school->slug, 'assessmentId' => $assessment->id]))->assertForbidden();
    }

    public function test_per_question_pills_report_the_right_state_for_each_answer(): void
    {
        [$school, $student, $owner, $assessment, $mcq, $essay] = $this->scenario('scrthree');
        $session = $this->attempt($student, $school, $assessment, $mcq, true);

        app(TenantManager::class)->use($school);
        $pills = app(ScoreReportingService::class)->pillsFor($session->fresh());
        app(TenantManager::class)->forget();

        $this->assertCount(2, $pills);
        // Answered correctly -> full marks.
        $this->assertSame(ScoreReportingService::STATE_FULL, $pills[0]['state']);
        $this->assertSame(10.0, $pills[0]['max']);
        // Essay left blank but still needs a human -> requires grading,
        // NOT "not answered", because a grader still has to see it.
        $this->assertSame(ScoreReportingService::STATE_REQUIRES, $pills[1]['state']);
    }

    public function test_a_wrong_answer_reads_as_zero_not_unanswered(): void
    {
        [$school, $student, $owner, $assessment, $mcq] = $this->scenario('scrfour');
        $session = $this->attempt($student, $school, $assessment, $mcq, false);

        app(TenantManager::class)->use($school);
        $pills = app(ScoreReportingService::class)->pillsFor($session->fresh());
        app(TenantManager::class)->forget();

        $this->assertSame(ScoreReportingService::STATE_ZERO, $pills[0]['state'],
            'an answered but wrong question is zero, which is different from never answering');
    }

    public function test_the_grade_method_decides_which_attempt_counts(): void
    {
        [$school, $student, $owner, $assessment, $mcq] = $this->scenario('scrfive', false);

        // Two attempts: one wrong (0), one right (10).
        $this->attempt($student, $school, $assessment, $mcq, false);
        $this->attempt($student, $school, $assessment, $mcq, true);

        app(TenantManager::class)->use($school);
        $reporting = app(ScoreReportingService::class);
        $fresh = CbtAssessment::find($assessment->id);

        $fresh->update(['grade_method' => 'highest']);
        $this->assertSame(10.0, $reporting->finalGrade($fresh->fresh(), User::class, $student->id)['score']);

        $fresh->update(['grade_method' => 'first']);
        $this->assertSame(0.0, $reporting->finalGrade($fresh->fresh(), User::class, $student->id)['score']);

        $fresh->update(['grade_method' => 'last']);
        $this->assertSame(10.0, $reporting->finalGrade($fresh->fresh(), User::class, $student->id)['score']);

        $fresh->update(['grade_method' => 'average']);
        $this->assertSame(5.0, $reporting->finalGrade($fresh->fresh(), User::class, $student->id)['score']);
        app(TenantManager::class)->forget();
    }

    public function test_an_unfinished_or_suspended_attempt_never_counts_toward_a_grade(): void
    {
        [$school, $student, $owner, $assessment, $mcq] = $this->scenario('scrsix', false);

        $this->attempt($student, $school, $assessment, $mcq, true); // scores 10

        // A second attempt left in progress, and a third suspended.
        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $live = CbtSession::where('status', CbtSession::STATUS_IN_PROGRESS)->first();
        $live->forceFill(['status' => CbtSession::STATUS_SUSPENDED, 'suspended_at' => now()])->save();

        $grade = app(ScoreReportingService::class)->finalGrade(CbtAssessment::find($assessment->id), User::class, $student->id);
        // Only the one finished attempt counts.
        $this->assertSame(1, $grade['attempts']);
        $this->assertSame(10.0, $grade['score']);
        app(TenantManager::class)->forget();
    }

    public function test_scores_export_returns_a_row_per_attempt(): void
    {
        [$school, $student, $owner, $assessment, $mcq] = $this->scenario('scrseven');
        $this->attempt($student, $school, $assessment, $mcq, true);

        $response = $this->actingAs($owner)->get(route('school.cbt.scores.export', [
            'school' => $school->slug, 'assessmentId' => $assessment->id, 'format' => 'csv',
        ]));

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('Taker,Status,Score', $body);
        $this->assertStringContainsString('Score Student', $body);
    }

    public function test_the_matrix_export_has_a_column_per_assessment(): void
    {
        [$school, $student, $owner, $assessment, $mcq] = $this->scenario('screight');
        $this->attempt($student, $school, $assessment, $mcq, true);

        $response = $this->actingAs($owner)->get(route('school.cbt.scores.matrix.export', ['school' => $school->slug]));

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('Scored Paper', $body);
        $this->assertStringContainsString('Total,Average', $body);
        $this->assertStringContainsString('Score Student', $body);
    }

    public function test_granting_a_retry_adds_an_extra_attempt_for_that_taker_only(): void
    {
        [$school, $student, $owner, $assessment, $mcq] = $this->scenario('scrnine');
        $session = $this->attempt($student, $school, $assessment, $mcq, true);

        $this->actingAs($owner)->post(route('school.cbt.scores.grant-retry', [
            'school' => $school->slug, 'sessionId' => $session->id,
        ]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $schedule = CbtSchedule::where('assessment_id', $assessment->id)->where('takeable_id', $student->id)->first();
        $this->assertNotNull($schedule);
        $this->assertSame(1, $schedule->extra_attempts);
        // Nobody else gained an attempt.
        $this->assertSame(1, CbtSchedule::count());
        app(TenantManager::class)->forget();
    }

    public function test_voiding_an_attempt_from_results_zeroes_it(): void
    {
        [$school, $student, $owner, $assessment, $mcq] = $this->scenario('scrten');
        $session = $this->attempt($student, $school, $assessment, $mcq, true);

        $this->actingAs($owner)->post(route('school.cbt.scores.void', [
            'school' => $school->slug, 'sessionId' => $session->id,
        ]), ['reason' => 'Confirmed collusion.'])->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertSame(0.0, (float) $fresh->total_score);
        $this->assertSame(CbtSession::STATUS_GRADED, $fresh->status);
        app(TenantManager::class)->forget();
    }
}
