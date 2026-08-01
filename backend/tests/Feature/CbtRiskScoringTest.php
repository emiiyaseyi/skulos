<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtSecurityEvent;
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
 * SKULOS Assessment Hub, Phase 2c: JS-only proctoring signals (tab
 * switch, fullscreen exit, copy/paste, right-click) logged as
 * CbtSecurityEvent rows, feeding a running risk score/level, and a
 * lock-tier profile auto-submitting once its own tab-switch limit is
 * crossed.
 */
class CbtRiskScoringTest extends TestCase
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

    protected function schoolWithSession(string $slug, string $profileName = 'Basic'): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $student = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $profile = CbtSecurityProfile::where('name', $profileName)->first();
        $paper = CbtAssessment::create(['title' => 'Quiz', 'duration_minutes' => 30, 'security_profile_id' => $profile->id, 'audience' => 'student']);
        $q = $this->makeTrueFalseQuestion('Q1', true);
        CbtAssessmentQuestion::create(['assessment_id' => $paper->id, 'question_id' => $q->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        return [$school, $student, $paper];
    }

    public function test_a_tab_switch_event_is_logged_and_raises_the_risk_score(): void
    {
        [$school, $student, $paper] = $this->schoolWithSession('crsone');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'type' => 'tab_switch',
        ])->assertOk()->assertJson(['recorded' => true, 'auto_submitted' => false]);

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertSame(1, $fresh->tab_switches);
        $this->assertSame(10, $fresh->risk_score);
        $this->assertSame('low', $fresh->risk_level);
        $this->assertSame(1, CbtSecurityEvent::where('session_id', $session->id)->where('type', 'tab_switch')->count());
        app(TenantManager::class)->forget();
    }

    public function test_risk_level_escalates_past_the_medium_and_high_thresholds(): void
    {
        [$school, $student, $paper] = $this->schoolWithSession('crstwo', 'Medium');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        // tab_switch=10 each; 3 switches = 30 -> medium; then a fullscreen_exit (+15) = 45, still medium; then another tab_switch (+10) = 55, still medium; one more (+10) = 65 -> high.
        foreach (['tab_switch', 'tab_switch', 'tab_switch'] as $type) {
            $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), ['type' => $type]);
        }
        app(TenantManager::class)->use($school);
        $this->assertSame('medium', $session->fresh()->risk_level);
        app(TenantManager::class)->forget();

        foreach (['fullscreen_exit', 'tab_switch', 'tab_switch'] as $type) {
            $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), ['type' => $type]);
        }
        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertSame('high', $fresh->risk_level);
        $this->assertTrue($fresh->is_flagged);
        app(TenantManager::class)->forget();
    }

    public function test_a_lock_tier_profile_auto_submits_once_the_tab_switch_limit_is_crossed(): void
    {
        [$school, $student, $paper] = $this->schoolWithSession('crsthree', 'High'); // High: tab_switch_action=lock, limit=3

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), ['type' => 'tab_switch']);
        $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), ['type' => 'tab_switch']);
        $response = $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), ['type' => 'tab_switch']);
        $response->assertJson(['auto_submitted' => true]);

        app(TenantManager::class)->use($school);
        // The fixture paper's one question is objective (true_false), so
        // submit() grades it immediately and the session finalizes
        // straight to "graded" — status genuinely leaves "auto_submitted"
        // once grading completes (see CbtSession::finalizeIfFullyGraded()'s
        // own docblock); submitted_at + the API's own auto_submitted:true
        // response above are what prove this really was a timeout/limit
        // auto-submit, not the taker's own action.
        $fresh = $session->fresh();
        $this->assertSame(CbtSession::STATUS_GRADED, $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
        app(TenantManager::class)->forget();
    }

    public function test_events_cannot_be_reported_on_a_session_that_already_ended(): void
    {
        [$school, $student, $paper] = $this->schoolWithSession('crsfour');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.submit', ['school' => $school->slug, 'sessionId' => $session->id]));
        $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), ['type' => 'tab_switch'])
            ->assertStatus(422);
    }
}
