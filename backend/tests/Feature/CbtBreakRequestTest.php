<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtPaper;
use App\Models\Tenant\CbtPaperQuestion;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\CbtSession;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SKULOS Assessment Hub, Phase 2g: a taker's break request, decided by
 * staff — approving must push the session's own `ends_at` forward so
 * break time is never silently deducted from the taker's exam time.
 */
class CbtBreakRequestTest extends TestCase
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

    protected function schoolWithSession(string $slug): array
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
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $paper = CbtPaper::create(['title' => 'Break Test Paper', 'duration_minutes' => 30, 'security_profile_id' => $basic->id, 'audience' => 'student']);
        $q = CbtQuestion::create(['type' => 'true_false', 'prompt' => 'Q1', 'correct_answer' => true, 'points' => 1]);
        CbtPaperQuestion::create(['paper_id' => $paper->id, 'question_id' => $q->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        return [$school, $student, $owner, $paper];
    }

    public function test_a_student_can_request_a_break_and_it_shows_on_the_live_monitor(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithSession('brone');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.break-request', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'reason' => 'Need water.',
        ])->assertOk()->assertJson(['requested' => true]);

        app(TenantManager::class)->use($school);
        $this->assertTrue($session->fresh()->break_requested);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get(route('school.cbt.live-monitor.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('Break requested');
    }

    public function test_approving_a_break_extends_the_sessions_own_end_time(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithSession('brtwo');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        $originalEndsAt = $session->ends_at->copy();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.break-request', ['school' => $school->slug, 'sessionId' => $session->id]), ['reason' => 'Need water.']);

        $this->actingAs($owner)->post(route('school.cbt.live-monitor.approve-break', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'minutes' => 5,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertFalse($fresh->break_requested);
        $this->assertTrue($fresh->isOnBreak());
        $this->assertEquals($originalEndsAt->addMinutes(5)->timestamp, $fresh->ends_at->timestamp);
        app(TenantManager::class)->forget();
    }

    public function test_denying_a_break_clears_the_request_without_touching_end_time(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithSession('brthree');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        $originalEndsAt = $session->ends_at->copy();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.break-request', ['school' => $school->slug, 'sessionId' => $session->id]), ['reason' => 'Need water.']);
        $this->actingAs($owner)->post(route('school.cbt.live-monitor.deny-break', ['school' => $school->slug, 'sessionId' => $session->id]));

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertFalse($fresh->break_requested);
        $this->assertFalse($fresh->isOnBreak());
        $this->assertEquals($originalEndsAt->timestamp, $fresh->ends_at->timestamp);
        app(TenantManager::class)->forget();
    }

    public function test_a_student_cannot_request_a_second_break_while_one_is_already_pending(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithSession('brfour');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.break-request', ['school' => $school->slug, 'sessionId' => $session->id]), ['reason' => 'A']);
        $this->actingAs($student)->post(route('school.cbt.take.break-request', ['school' => $school->slug, 'sessionId' => $session->id]), ['reason' => 'B'])
            ->assertStatus(422);
    }
}
