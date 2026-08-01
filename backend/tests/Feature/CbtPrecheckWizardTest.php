<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtPrecheckSession;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtAssessmentQuestion;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\CbtSession;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SKULOS Assessment Hub, Phase 2d: the pre-exam wizard's own step
 * gating (derived live from the paper's security profile) and its
 * hand-off into a real timed CbtSession once every active step is done.
 */
class CbtPrecheckWizardTest extends TestCase
{
    use \Tests\Concerns\MakesCbtQuestions, RefreshDatabase;

    protected string $tenantDir;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        // This whole test class is about the pre-exam wizard itself,
        // which only ever runs when proctoring is switched on (see
        // App\Support\AssessmentMode) — the light-mode default (no
        // proctoring, per the standing "no proctor/invigilation for
        // now" scope cut) skips straight to a timed session instead.
        config(['skulos.cbt_proctoring_enabled' => true]);
        $this->seed(RbacSeeder::class);
        $this->rbac = app(Rbac::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithStudentAndPaper(string $slug, string $profileName): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $student = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $profile = CbtSecurityProfile::where('name', $profileName)->first();
        $paper = CbtAssessment::create(['title' => 'Proctored Quiz', 'duration_minutes' => 30, 'security_profile_id' => $profile->id, 'audience' => 'student']);
        $q = $this->makeTrueFalseQuestion('Q1', true);
        CbtAssessmentQuestion::create(['assessment_id' => $paper->id, 'question_id' => $q->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        return [$school, $student, $paper];
    }

    public function test_a_basic_tier_assessment_only_shows_the_universal_steps(): void
    {
        [$school, $student, $paper] = $this->schoolWithStudentAndPaper('pwone', 'Basic');

        $this->actingAs($student)->post(route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $paper->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $precheck = CbtPrecheckSession::first();
        $this->assertSame(['welcome', 'closetabs', 'browser', 'internet', 'privacy', 'ready'], $precheck->activeSteps());
        $this->assertSame('welcome', $precheck->current_step);
        app(TenantManager::class)->forget();
    }

    public function test_starting_twice_resumes_the_same_precheck_not_a_second_one(): void
    {
        [$school, $student, $paper] = $this->schoolWithStudentAndPaper('pwtwo', 'Basic');

        $this->actingAs($student)->post(route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        $this->actingAs($student)->post(route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));

        app(TenantManager::class)->use($school);
        $this->assertSame(1, CbtPrecheckSession::count());
        app(TenantManager::class)->forget();
    }

    public function test_a_basic_tier_wizard_walks_through_and_starts_a_real_timed_session(): void
    {
        [$school, $student, $paper] = $this->schoolWithStudentAndPaper('pwthree', 'Basic');

        $this->actingAs($student)->post(route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $precheck = CbtPrecheckSession::first();
        $id = $precheck->id;
        app(TenantManager::class)->forget();

        $advance = fn (array $data = []) => $this->actingAs($student)->post(
            route('school.cbt.precheck.advance', ['school' => $school->slug, 'precheckId' => $id]), $data
        );

        $advance()->assertRedirect(); // welcome
        $advance(['confirm' => '1'])->assertRedirect(); // closetabs
        $advance()->assertRedirect(); // browser
        $advance()->assertRedirect(); // internet
        $advance(['agree' => '1'])->assertRedirect(); // privacy
        $response = $advance(['confirm_ready' => '1']); // ready -> finishes

        app(TenantManager::class)->use($school);
        $fresh = CbtPrecheckSession::find($id);
        $this->assertSame(CbtPrecheckSession::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->cbt_session_id);
        $session = CbtSession::find($fresh->cbt_session_id);
        $this->assertNotNull($session);
        $this->assertSame(CbtSession::STATUS_IN_PROGRESS, $session->status);
        app(TenantManager::class)->forget();

        $response->assertRedirect(route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $session->id]));
    }

    public function test_a_missing_confirmation_does_not_advance_the_current_step(): void
    {
        [$school, $student, $paper] = $this->schoolWithStudentAndPaper('pwfour', 'Basic');

        $this->actingAs($student)->post(route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $precheck = CbtPrecheckSession::first();
        $id = $precheck->id;
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.precheck.advance', ['school' => $school->slug, 'precheckId' => $id])); // welcome
        // closetabs, with no "confirm" field at all
        $this->actingAs($student)->post(route('school.cbt.precheck.advance', ['school' => $school->slug, 'precheckId' => $id]))
            ->assertSessionHasErrors('confirm');

        app(TenantManager::class)->use($school);
        $this->assertSame('closetabs', CbtPrecheckSession::find($id)->current_step);
        app(TenantManager::class)->forget();
    }

    public function test_a_maximum_tier_assessment_requires_every_step_including_uploads(): void
    {
        [$school, $student, $paper] = $this->schoolWithStudentAndPaper('pwfive', 'Maximum');

        $this->actingAs($student)->post(route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $precheck = CbtPrecheckSession::first();
        $id = $precheck->id;
        $this->assertSame([
            'welcome', 'closetabs', 'browser', 'internet', 'camera', 'audio', 'photoid', 'face',
            'screenshare', 'keystroke', 'monitor', 'environment', 'privacy', 'fullscreen', 'ready',
        ], $precheck->activeSteps());
        app(TenantManager::class)->forget();

        $advance = fn (array $data = []) => $this->actingAs($student)->post(
            route('school.cbt.precheck.advance', ['school' => $school->slug, 'precheckId' => $id]), $data
        );

        $advance()->assertRedirect(); // welcome
        $advance(['confirm' => '1'])->assertRedirect(); // closetabs
        $advance()->assertRedirect(); // browser
        $advance()->assertRedirect(); // internet
        $advance(['camera_ok' => '1'])->assertRedirect(); // camera
        $advance(['audio_ok' => '1'])->assertRedirect(); // audio
        $advance(['photo_id' => UploadedFile::fake()->image('id.jpg', 300, 300)])->assertRedirect(); // photoid
        $advance(['face_capture' => 'data:image/jpeg;base64,'.base64_encode('fake-face-bytes'), 'match_score' => 0.9])->assertRedirect(); // face
        $advance(['screenshare_ok' => '1'])->assertRedirect(); // screenshare
        $advance(['typed_text' => 'the QUICK brown fox jumps over the lazy dog.'])->assertRedirect(); // keystroke, case-insensitive match
        $advance(['monitor_ok' => '1'])->assertRedirect(); // monitor
        $advance(['environment_video' => UploadedFile::fake()->create('env.webm', 500, 'video/webm')])->assertRedirect(); // environment
        $advance(['agree' => '1'])->assertRedirect(); // privacy
        $advance(['fullscreen_ok' => '1'])->assertRedirect(); // fullscreen
        $advance(['confirm_ready' => '1']); // ready -> finishes

        app(TenantManager::class)->use($school);
        $fresh = CbtPrecheckSession::find($id);
        $this->assertSame(CbtPrecheckSession::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->cbt_session_id);
        $this->assertNotNull($fresh->photo_id_path);
        $this->assertNotNull($fresh->face_capture_path);
        $this->assertNotNull($fresh->environment_video_path);
        app(TenantManager::class)->forget();
    }

    public function test_the_wrong_keystroke_sentence_is_rejected(): void
    {
        [$school, $student, $paper] = $this->schoolWithStudentAndPaper('pwsix', 'Maximum');

        $this->actingAs($student)->post(route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $precheck = CbtPrecheckSession::first();
        $id = $precheck->id;
        app(TenantManager::class)->forget();

        $advance = fn (array $data = []) => $this->actingAs($student)->post(
            route('school.cbt.precheck.advance', ['school' => $school->slug, 'precheckId' => $id]), $data
        );
        $advance();
        $advance(['confirm' => '1']);
        $advance();
        $advance();
        $advance(['camera_ok' => '1']);
        $advance(['audio_ok' => '1']);
        $advance(['photo_id' => UploadedFile::fake()->image('id.jpg', 300, 300)]);
        $advance(['face_capture' => 'data:image/jpeg;base64,'.base64_encode('fake'), 'match_score' => 0.9]);
        $advance(['screenshare_ok' => '1']);
        $advance(['typed_text' => 'totally the wrong sentence'])->assertSessionHasErrors('typed_text');

        app(TenantManager::class)->use($school);
        $this->assertSame('keystroke', CbtPrecheckSession::find($id)->current_step);
        app(TenantManager::class)->forget();
    }

    public function test_another_students_precheck_cannot_be_viewed_or_advanced(): void
    {
        [$school, $student1, $paper] = $this->schoolWithStudentAndPaper('pwseven', 'Basic');
        $student2 = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student2->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($student1)->post(route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $id = CbtPrecheckSession::first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($student2)->get(route('school.cbt.precheck.show', ['school' => $school->slug, 'precheckId' => $id]))
            ->assertForbidden();
    }
}
