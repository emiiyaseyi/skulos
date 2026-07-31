<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtIncident;
use App\Models\Tenant\CbtPaper;
use App\Models\Tenant\CbtPaperQuestion;
use App\Models\Tenant\CbtPrecheckSession;
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
 * SKULOS Assessment Hub, Phase 2g: the live monitor grid (in-progress
 * sessions + still-in-precheck takers) and the incidents queue,
 * including the automatic "risk crossed high" incident.
 */
class CbtLiveMonitorTest extends TestCase
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

    protected function schoolWithStudentAndOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $student = User::factory()->create(['name' => 'Live Monitor Student']);
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $paper = CbtPaper::create(['title' => 'Monitor Test Paper', 'duration_minutes' => 30, 'security_profile_id' => $basic->id, 'audience' => 'student']);
        $q = CbtQuestion::create(['type' => 'true_false', 'prompt' => 'Q1', 'correct_answer' => true, 'points' => 1]);
        CbtPaperQuestion::create(['paper_id' => $paper->id, 'question_id' => $q->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        return [$school, $student, $owner, $paper];
    }

    public function test_the_live_monitor_shows_an_in_progress_session_and_a_still_in_precheck_taker(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithStudentAndOwner('lmone');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]));

        $student2 = User::factory()->create(['name' => 'Still In Precheck']);
        app(TenantManager::class)->use($school);
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        app(TenantManager::class)->forget();
        $school->users()->attach($student2->id, ['role_id' => $studentRole->id, 'status' => 'active']);
        $this->actingAs($student2)->post(route('school.cbt.precheck.start', ['school' => $school->slug, 'paperId' => $paper->id]));

        $response = $this->actingAs($owner)->get(route('school.cbt.live-monitor.index', ['school' => $school->slug]));
        $response->assertOk()->assertSee('Live Monitor Student')->assertSee('Still In Precheck');
    }

    public function test_a_student_cannot_reach_the_live_monitor(): void
    {
        [$school, $student] = $this->schoolWithStudentAndOwner('lmtwo');

        $this->actingAs($student)->get(route('school.cbt.live-monitor.index', ['school' => $school->slug]))
            ->assertForbidden();
    }

    public function test_risk_crossing_high_automatically_raises_an_incident(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithStudentAndOwner('lmthree');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        // High tier's tab_switch_action=lock would auto-submit before
        // reaching risk 60 on Basic's own profile, so drive the score up
        // directly via repeated tab_switch events (10 each) on Basic
        // (tab_switch_action=none, never auto-submits).
        for ($i = 0; $i < 7; $i++) {
            $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), ['type' => 'tab_switch']);
        }

        app(TenantManager::class)->use($school);
        $this->assertTrue($session->fresh()->is_flagged);
        $this->assertSame(1, CbtIncident::where('type', 'high_risk_crossing')->count());
        app(TenantManager::class)->forget();

        $incidentId = null;
        app(TenantManager::class)->use($school);
        $incidentId = CbtIncident::first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get(route('school.cbt.incidents.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('High risk crossing');

        $this->actingAs($owner)->post(route('school.cbt.incidents.resolve', ['school' => $school->slug, 'incidentId' => $incidentId]), [
            'notes' => 'Spoke to the student, false alarm.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(CbtIncident::STATUS_RESOLVED, CbtIncident::find($incidentId)->status);
        app(TenantManager::class)->forget();
    }

    public function test_a_staff_member_can_manually_raise_an_incident(): void
    {
        [$school, $student, $owner, $paper] = $this->schoolWithStudentAndOwner('lmfour');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.incidents.raise', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'description' => 'Saw a second monitor in the background.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(1, CbtIncident::where('type', 'manual')->count());
        app(TenantManager::class)->forget();
    }
}
