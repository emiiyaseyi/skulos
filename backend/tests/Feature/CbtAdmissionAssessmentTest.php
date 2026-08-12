<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\CbtSession;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SKULOS Assessment Hub, applicant audience (user directive
 * 2026-08-01): an Admission Assessment taken by an Applicant on the
 * 'applicant' guard, through AdmissionAssessmentController rather than
 * CbtTakeController — same underlying engine, a separate controller
 * because Applicant is never a School\User (see that model's own
 * docblock). Also covers the pipeline auto-advance a passing result
 * triggers (config('skulos.admission_pipeline_stages.admission_assessment')).
 */
class CbtAdmissionAssessmentTest extends TestCase
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
     * @return array{0: School, 1: Applicant, 2: CbtAssessment, 3: \App\Models\Tenant\CbtQuestion}
     */
    protected function scenario(string $slug, array $applicantOverrides = [], array $assessmentOverrides = []): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', Module::STATE_ENABLED);
        $this->rbac->setModuleState($school, 'admissions', Module::STATE_ENABLED);

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS1', 'sequence' => 1]);
        $academicSession = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);

        $applicant = Applicant::forceCreate(array_merge([
            'name' => 'Ada Obi', 'phone' => '08011112222', 'admission_number' => 'APP/0001',
            'session_id' => $academicSession->id, 'school_class_id' => $class->id,
            'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
        ], $applicantOverrides));

        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $question = $this->makeChoiceQuestion('What is 2 + 2?', ['3', '4', '5'], ['4']);
        $assessment = CbtAssessment::create(array_merge([
            'title' => 'JSS1 Admission Assessment', 'duration_minutes' => 60,
            'security_profile_id' => $basic->id, 'audience' => CbtAssessment::AUDIENCE_APPLICANT,
            'pass_mark' => 1, 'pass_message' => 'You have successfully moved to the next stage of the application process.',
            'fail_message' => 'You did not meet the pass mark this time.',
        ], $assessmentOverrides));
        \App\Models\Tenant\CbtAssessmentQuestion::create([
            'assessment_id' => $assessment->id, 'question_id' => $question->id, 'sequence' => 0,
        ]);
        app(TenantManager::class)->forget();

        return [$school, $applicant, $assessment, $question];
    }

    public function test_an_eligible_applicant_can_see_start_answer_and_submit_an_admission_assessment(): void
    {
        // Index 3 in the default chain is "Admission Assessment" (see
        // config('skulos.default_admission_pipeline_chain')) — set
        // explicitly so the pass path below has something real to
        // advance from, rather than the freshly-created default of 0
        // ("Application Started").
        [$school, $applicant, $assessment, $question] = $this->scenario('adm1', ['pipeline_stage' => 3]);
        $stageBefore = $applicant->pipeline_stage;

        $this->actingAs($applicant, 'applicant')
            ->get(route('school.admissions.assessment.available', ['school' => $school->slug]))
            ->assertOk()->assertSee('JSS1 Admission Assessment');

        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $session = CbtSession::where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)->first();
        $this->assertNotNull($session, 'a real CbtSession should exist for the applicant');
        $this->assertSame($assessment->id, $session->assessment_id);
        $correctAnswerId = $this->answerIdFor($question, '4');
        app(TenantManager::class)->forget();

        $this->actingAs($applicant, 'applicant')
            ->postJson(route('school.admissions.assessment.answer', ['school' => $school->slug, 'sessionId' => $session->id]), [
                'question_id' => $question->id, 'answer' => (string) $correctAnswerId,
            ])->assertOk()->assertJson(['saved' => true]);

        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.submit', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertRedirect(route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]));

        $this->actingAs($applicant, 'applicant')
            ->get(route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertOk()
            ->assertSee('Nice work, Ada Obi!')
            ->assertSee('Passed')
            ->assertSee('You have successfully moved to the next stage of the application process.');

        app(TenantManager::class)->use($school);
        $this->assertSame($stageBefore + 1, $applicant->fresh()->pipeline_stage, 'a passing result should advance the applicant past Admission Assessment');
        app(TenantManager::class)->forget();
    }

    public function test_an_applicant_ineligible_for_the_wrong_class_is_forbidden(): void
    {
        [$school, , $assessment] = $this->scenario('adm2');

        app(TenantManager::class)->use($school);
        $otherSection = Section::create(['name' => 'Secondary B', 'sequence' => 2]);
        $otherClass = SchoolClass::create(['section_id' => $otherSection->id, 'name' => 'JSS2', 'sequence' => 2]);
        $otherApplicant = Applicant::forceCreate([
            'name' => 'Bello Musa', 'phone' => '08033334444', 'admission_number' => 'APP/0002',
            'session_id' => AcademicSession::first()->id, 'school_class_id' => $otherClass->id,
            'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
        ]);
        $assessment->forceFill(['applicant_school_class_id' => \App\Models\Tenant\SchoolClass::where('name', 'JSS1')->value('id')])->save();
        app(TenantManager::class)->forget();

        $this->actingAs($otherApplicant, 'applicant')
            ->post(route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]))
            ->assertForbidden();
    }

    public function test_failing_the_pass_mark_does_not_advance_the_pipeline_stage(): void
    {
        [$school, $applicant, $assessment, $question] = $this->scenario('adm3');

        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)->first();
        $wrongAnswerId = $this->answerIdFor($question, '3');
        $stageBefore = $applicant->fresh()->pipeline_stage;
        app(TenantManager::class)->forget();

        $this->actingAs($applicant, 'applicant')->postJson(
            route('school.admissions.assessment.answer', ['school' => $school->slug, 'sessionId' => $session->id]),
            ['question_id' => $question->id, 'answer' => (string) $wrongAnswerId]
        );
        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        $this->actingAs($applicant, 'applicant')
            ->get(route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertOk()->assertSee('Not yet passed');

        app(TenantManager::class)->use($school);
        $this->assertSame($stageBefore, $applicant->fresh()->pipeline_stage, 'a failed attempt must not advance the pipeline');
        app(TenantManager::class)->forget();
    }

    public function test_another_applicants_session_cannot_be_viewed_or_answered(): void
    {
        [$school, $applicant, $assessment] = $this->scenario('adm4');

        app(TenantManager::class)->use($school);
        $intruder = Applicant::forceCreate([
            'name' => 'Chika Eze', 'phone' => '08055556666', 'admission_number' => 'APP/0003',
            'session_id' => AcademicSession::first()->id, 'school_class_id' => SchoolClass::first()->id,
            'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)->first();
        app(TenantManager::class)->forget();

        $this->actingAs($intruder, 'applicant')
            ->get(route('school.admissions.assessment.session', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertForbidden();
    }

    public function test_the_result_screen_shows_the_configured_pass_mark(): void
    {
        [$school, $applicant, $assessment, $question] = $this->scenario('adm8', [], ['pass_mark' => 70]);
        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)->first();
        $wrongAnswerId = $this->answerIdFor($question, '3');
        app(TenantManager::class)->forget();

        $this->actingAs($applicant, 'applicant')->postJson(
            route('school.admissions.assessment.answer', ['school' => $school->slug, 'sessionId' => $session->id]),
            ['question_id' => $question->id, 'answer' => (string) $wrongAnswerId]
        );
        $this->actingAs($applicant, 'applicant')->post(route('school.admissions.assessment.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        $this->actingAs($applicant, 'applicant')
            ->get(route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertOk()
            ->assertSee('Pass mark: 70%');
    }

    /**
     * User correction 2026-08-12, after seeing the first (wrong)
     * implementation live: "this is not the full breakdown, check the
     * question bank again, the assessment was made of many questions
     * banks which is why i need to see here score in Mathematic,
     * English Language etc" — a single admission assessment is built
     * from MULTIPLE question banks (CbtQuestionPool, each pool a
     * separately labelled section drawing from its own category), not
     * from multiple separate CbtAssessment rows. The breakdown must be
     * within one session's own pools, not across sibling assessments.
     */
    public function test_the_result_screen_breaks_the_score_down_by_question_bank(): void
    {
        [$school, $applicant, $assessment] = $this->scenario('adm9', [], ['pass_mark' => null]);

        app(TenantManager::class)->use($school);
        // scenario() already attached one fixed question worth 1 mark
        // (general questions, no pool) — add two real question banks on
        // top of it, one per subject, 10 marks each.
        $maths = \App\Models\Tenant\CbtQuestionCategory::create(['name' => 'Mathematics']);
        $mathsQuestion = $this->makeTrueFalseQuestion('2 + 2 = 4', true)->fresh();
        $mathsQuestion->update(['category_id' => $maths->id]);
        \App\Models\Tenant\CbtQuestionPool::create([
            'assessment_id' => $assessment->id, 'category_id' => $maths->id,
            'draw_count' => 1, 'points_each' => 10, 'label' => 'Mathematics',
        ]);

        $english = \App\Models\Tenant\CbtQuestionCategory::create(['name' => 'English Language']);
        $englishQuestion = $this->makeTrueFalseQuestion('The sky is blue', true)->fresh();
        $englishQuestion->update(['category_id' => $english->id]);
        \App\Models\Tenant\CbtQuestionPool::create([
            'assessment_id' => $assessment->id, 'category_id' => $english->id,
            'draw_count' => 1, 'points_each' => 10, 'label' => 'English Language',
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)->first();
        $given = \App\Models\Tenant\CbtSessionQuestion::with('question.answers')->where('session_id', $session->id)->get();
        app(TenantManager::class)->forget();

        // Answer the Mathematics-bank question correctly, leave the
        // English-bank question and the general question unanswered.
        $mathsGiven = $given->first(fn ($sq) => $sq->question->prompt === '2 + 2 = 4');
        $trueId = $mathsGiven->question->answers->firstWhere('answer_text', 'True')->id;
        $this->actingAs($applicant, 'applicant')->postJson(
            route('school.admissions.assessment.answer', ['school' => $school->slug, 'sessionId' => $session->id]),
            ['question_id' => $mathsGiven->question_id, 'answer' => (string) $trueId]
        );
        $this->actingAs($applicant, 'applicant')->post(route('school.admissions.assessment.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        $this->actingAs($applicant, 'applicant')
            ->get(route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertOk()
            ->assertSee('Scores by subject')
            ->assertSee('Mathematics')
            ->assertSee('English Language')
            ->assertSee('General questions');

        app(TenantManager::class)->use($school);
        $breakdown = collect($session->fresh()->scoreBreakdown())->keyBy('label');
        app(TenantManager::class)->forget();

        $this->assertEquals(10.0, $breakdown['Mathematics']['earned']);
        $this->assertEquals(10.0, $breakdown['Mathematics']['max']);
        $this->assertEquals(0.0, $breakdown['English Language']['earned']);
        $this->assertEquals(10.0, $breakdown['English Language']['max']);
        $this->assertEquals(0.0, $breakdown['General questions']['earned']);
        $this->assertEquals(1.0, $breakdown['General questions']['max']);
    }

    public function test_a_single_bank_assessment_shows_no_breakdown_table(): void
    {
        [$school, $applicant, $assessment, $question] = $this->scenario('adm10');
        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)->first();
        $correctAnswerId = $this->answerIdFor($question, '4');
        app(TenantManager::class)->forget();

        $this->actingAs($applicant, 'applicant')->postJson(
            route('school.admissions.assessment.answer', ['school' => $school->slug, 'sessionId' => $session->id]),
            ['question_id' => $question->id, 'answer' => (string) $correctAnswerId]
        );
        $this->actingAs($applicant, 'applicant')->post(route('school.admissions.assessment.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        $this->actingAs($applicant, 'applicant')
            ->get(route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertOk()
            ->assertDontSee('Scores by subject');
    }

    public function test_the_result_screen_shows_a_real_percentage_and_the_applicants_name(): void
    {
        [$school, $applicant, $assessment, $question] = $this->scenario('adm5');
        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)->first();
        $correctAnswerId = $this->answerIdFor($question, '4');
        app(TenantManager::class)->forget();

        $this->actingAs($applicant, 'applicant')->postJson(
            route('school.admissions.assessment.answer', ['school' => $school->slug, 'sessionId' => $session->id]),
            ['question_id' => $question->id, 'answer' => (string) $correctAnswerId]
        );
        $this->actingAs($applicant, 'applicant')->post(route('school.admissions.assessment.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        $this->actingAs($applicant, 'applicant')
            ->get(route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertOk()
            ->assertSee('100%')
            ->assertSee('Ada Obi');
    }

    public function test_an_assessment_with_no_pass_mark_shows_no_pass_fail_tile(): void
    {
        [$school, $applicant, $assessment, $question] = $this->scenario('adm6', [], ['pass_mark' => null]);
        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)->first();
        $correctAnswerId = $this->answerIdFor($question, '4');
        app(TenantManager::class)->forget();

        $this->actingAs($applicant, 'applicant')->postJson(
            route('school.admissions.assessment.answer', ['school' => $school->slug, 'sessionId' => $session->id]),
            ['question_id' => $question->id, 'answer' => (string) $correctAnswerId]
        );
        $this->actingAs($applicant, 'applicant')->post(route('school.admissions.assessment.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        $this->actingAs($applicant, 'applicant')
            ->get(route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertOk()
            ->assertSee('100%')
            ->assertDontSee('Passed')
            ->assertDontSee('Not yet passed');
    }

    public function test_a_written_answer_still_pending_review_shows_the_awaiting_grading_state(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'adm7']));
        $this->rbac->setModuleState($school, 'assessment_hub', Module::STATE_ENABLED);
        $this->rbac->setModuleState($school, 'admissions', Module::STATE_ENABLED);

        app(TenantManager::class)->use($school);
        $academicSession = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $applicant = Applicant::forceCreate([
            'name' => 'Tunde Bello', 'phone' => '08099998888', 'admission_number' => 'APP/0009',
            'session_id' => $academicSession->id, 'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
        ]);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $question = $this->makeEssayQuestion('Describe your favourite subject.');
        $assessment = CbtAssessment::create([
            'title' => 'Essay Assessment', 'duration_minutes' => 60,
            'security_profile_id' => $basic->id, 'audience' => CbtAssessment::AUDIENCE_APPLICANT,
        ]);
        \App\Models\Tenant\CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $question->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)->first();
        app(TenantManager::class)->forget();

        $this->actingAs($applicant, 'applicant')->postJson(
            route('school.admissions.assessment.answer', ['school' => $school->slug, 'sessionId' => $session->id]),
            ['question_id' => $question->id, 'answer' => 'My favourite subject is Mathematics.']
        );
        $this->actingAs($applicant, 'applicant')->post(route('school.admissions.assessment.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        $this->actingAs($applicant, 'applicant')
            ->get(route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertOk()
            ->assertSee('still being reviewed')
            ->assertDontSee('Passed');
    }
}
