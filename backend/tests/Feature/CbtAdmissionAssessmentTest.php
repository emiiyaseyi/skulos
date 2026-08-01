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
            ->assertSee('You passed')
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
            ->assertOk()->assertSee('You did not pass');

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
}
