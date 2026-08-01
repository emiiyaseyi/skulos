<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtFaceApproval;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
use App\Models\Tenant\CbtPrecheckSession;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SKULOS Assessment Hub, Phase 2e: a live face match that fails 3
 * client-side attempts falls back to a staff-reviewed queue rather
 * than being hard-blocked forever — see CbtPrecheckController::
 * requestFaceReview()'s own docblock for the exact flow this mirrors
 * from the reference (Learn-Proctor-Secure-Browser) repo.
 */
class CbtFaceApprovalTest extends TestCase
{
    use \Tests\Concerns\MakesCbtQuestions, RefreshDatabase;

    protected string $tenantDir;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        // Face matching only ever runs inside the pre-exam wizard, which
        // only ever runs when proctoring is switched on (see
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

    protected function schoolWithStudentAndOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $student = User::factory()->create();
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $maximum = CbtSecurityProfile::where('name', 'Maximum')->first();
        $paper = CbtAssessment::create(['title' => 'Locked Down Quiz', 'duration_minutes' => 30, 'security_profile_id' => $maximum->id, 'audience' => 'student']);
        $q = $this->makeTrueFalseQuestion('Q1', true);
        CbtAssessmentQuestion::create(['assessment_id' => $paper->id, 'question_id' => $q->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        return [$school, $student, $owner, $paper];
    }

    protected function precheckToFaceStep($school, $student, $paper): int
    {
        $this->actingAs($student)->post(route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $id = CbtPrecheckSession::first()->id;
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
        $advance(['photo_id' => \Illuminate\Http\UploadedFile::fake()->image('id.jpg', 300, 300)]);

        return $id;
    }

    public function test_a_failed_match_request_creates_a_pending_approval_and_does_not_advance_the_step(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithStudentAndOwner('fatone');
        $id = $this->precheckToFaceStep($school, $student, $paper);

        $this->actingAs($student)->post(route('school.cbt.precheck.face-review', ['school' => $school->slug, 'precheckId' => $id]), [
            'face_capture' => 'data:image/jpeg;base64,'.base64_encode('low-match'),
            'match_score' => 0.2,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame('face', CbtPrecheckSession::find($id)->current_step);
        $approval = CbtFaceApproval::first();
        $this->assertNotNull($approval);
        $this->assertSame(CbtFaceApproval::STATUS_PENDING, $approval->status);
        $this->assertSame(0.2, $approval->match_score);
        app(TenantManager::class)->forget();
    }

    public function test_approving_advances_the_wizard_on_the_takers_next_visit(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithStudentAndOwner('fattwo');
        $id = $this->precheckToFaceStep($school, $student, $paper);

        $this->actingAs($student)->post(route('school.cbt.precheck.face-review', ['school' => $school->slug, 'precheckId' => $id]), [
            'face_capture' => 'data:image/jpeg;base64,'.base64_encode('low-match'),
            'match_score' => 0.2,
        ]);

        app(TenantManager::class)->use($school);
        $approvalId = CbtFaceApproval::first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.face-approvals.decide', ['school' => $school->slug, 'approvalId' => $approvalId]), [
            'decision' => 'approve',
        ])->assertRedirect();

        // The taker's next visit to the wizard advances past 'face' automatically.
        $this->actingAs($student)->get(route('school.cbt.precheck.show', ['school' => $school->slug, 'precheckId' => $id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNotSame('face', CbtPrecheckSession::find($id)->current_step);
        app(TenantManager::class)->forget();
    }

    public function test_a_rejected_request_permanently_blocks_the_face_step(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithStudentAndOwner('fatthree');
        $id = $this->precheckToFaceStep($school, $student, $paper);

        $this->actingAs($student)->post(route('school.cbt.precheck.face-review', ['school' => $school->slug, 'precheckId' => $id]), [
            'face_capture' => 'data:image/jpeg;base64,'.base64_encode('low-match'),
            'match_score' => 0.2,
        ]);
        app(TenantManager::class)->use($school);
        $approvalId = CbtFaceApproval::first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.face-approvals.decide', ['school' => $school->slug, 'approvalId' => $approvalId]), [
            'decision' => 'reject', 'notes' => 'Did not match at all.',
        ]);

        $this->actingAs($student)->get(route('school.cbt.precheck.show', ['school' => $school->slug, 'precheckId' => $id]))
            ->assertOk()->assertSee('rejected');

        app(TenantManager::class)->use($school);
        $this->assertSame('face', CbtPrecheckSession::find($id)->current_step);
        app(TenantManager::class)->forget();
    }

    public function test_a_student_cannot_reach_the_admin_approval_queue(): void
    {
        [$school, $student] = $this->schoolWithStudentAndOwner('fatfour');

        $this->actingAs($student)->get(route('school.cbt.face-approvals.index', ['school' => $school->slug]))
            ->assertForbidden();
    }

    public function test_deciding_an_already_decided_request_is_rejected(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithStudentAndOwner('fatfive');
        $id = $this->precheckToFaceStep($school, $student, $paper);

        $this->actingAs($student)->post(route('school.cbt.precheck.face-review', ['school' => $school->slug, 'precheckId' => $id]), [
            'face_capture' => 'data:image/jpeg;base64,'.base64_encode('low-match'),
            'match_score' => 0.2,
        ]);
        app(TenantManager::class)->use($school);
        $approvalId = CbtFaceApproval::first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.face-approvals.decide', ['school' => $school->slug, 'approvalId' => $approvalId]), ['decision' => 'approve']);
        $this->actingAs($owner)->post(route('school.cbt.face-approvals.decide', ['school' => $school->slug, 'approvalId' => $approvalId]), ['decision' => 'reject'])
            ->assertStatus(422);
    }
}
