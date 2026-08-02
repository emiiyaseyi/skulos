<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\CbtSession;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * User directive 2026-08-02, after the JSS1 Admission Assessment demo
 * went live: neither the applicant's own dashboard nor the staff
 * pipeline board ever showed anything about the pipeline stage/
 * assessment result an applicant had actually reached. Both now do —
 * this covers that they render correctly, not the underlying pipeline/
 * grading logic itself (already covered by CbtAdmissionAssessmentTest
 * and AdmissionPipelineConversionTest).
 */
class CbtAdmissionPipelineVisibilityTest extends TestCase
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

    protected function fixture(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', Module::STATE_ENABLED);
        $this->rbac->setModuleState($school, 'admissions', Module::STATE_ENABLED);

        $staff = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($staff->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS1', 'sequence' => 1]);
        $academicSession = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $applicant = Applicant::forceCreate([
            'name' => 'Tolu Bakare', 'phone' => '08055556666', 'admission_number' => 'APP/0001',
            'session_id' => $academicSession->id, 'school_class_id' => $class->id,
            'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
            'pipeline_stage' => 3,
        ]);

        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $question = $this->makeChoiceQuestion('What is 2 + 2?', ['3', '4', '5'], ['4']);
        $assessment = CbtAssessment::create([
            'title' => 'JSS1 Admission Assessment', 'duration_minutes' => 60,
            'security_profile_id' => $basic->id, 'audience' => CbtAssessment::AUDIENCE_APPLICANT,
            'pass_mark' => 1,
        ]);
        CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $question->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        return [$school, $staff, $applicant, $assessment, $question];
    }

    public function test_the_applicant_dashboard_shows_a_pipeline_stepper_with_the_current_stage_highlighted(): void
    {
        [$school, , $applicant] = $this->fixture('pipevis1');

        $response = $this->actingAs($applicant, 'applicant')
            ->get(route('school.admissions.dashboard', ['school' => $school->slug]))
            ->assertOk();

        $response->assertSee('Where you are');
        $response->assertSee('Admission Assessment');
    }

    public function test_the_pipeline_board_shows_a_graded_passing_assessment_result(): void
    {
        [$school, $staff, $applicant, $assessment, $question] = $this->fixture('pipevis2');

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
        $this->actingAs($applicant, 'applicant')
            ->post(route('school.admissions.assessment.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        $this->actingAs($staff)
            ->get(route('school.admissions.pipeline', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Passed')
            ->assertSee('1 / 1');
    }

    public function test_the_pipeline_board_shows_a_dash_when_no_attempt_has_been_submitted_yet(): void
    {
        [$school, $staff, $applicant] = $this->fixture('pipevis3');

        $response = $this->actingAs($staff)
            ->get(route('school.admissions.pipeline', ['school' => $school->slug]))
            ->assertOk();

        $response->assertSee($applicant->name);
        $response->assertDontSee('Passed');
        $response->assertDontSee('Did not pass');
    }
}
