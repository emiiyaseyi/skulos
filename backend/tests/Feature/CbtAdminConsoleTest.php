<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
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
 * The Admin Backend console (the reference repo's siteadmin.php): its
 * tabs, and the invigilator controls over a running attempt —
 * suspend, reinstate, force submit, pause an entire assessment, and
 * hold or release results.
 */
class CbtAdminConsoleTest extends TestCase
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

    protected function schoolWith(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $student = User::factory()->create(['name' => 'Console Student']);
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $assessment = CbtAssessment::create([
            'title' => 'Console Assessment', 'duration_minutes' => 30,
            'security_profile_id' => $basic->id, 'audience' => 'student',
        ]);
        $q = $this->makeTrueFalseQuestion('Q1.', true);
        CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $q->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        return [$school, $student, $owner, $assessment];
    }

    protected function beginAttempt($student, $school, $assessment): CbtSession
    {
        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        return $session;
    }

    public function test_every_console_tab_renders(): void
    {
        [$school, $student, $owner, $assessment] = $this->schoolWith('conone');
        $this->beginAttempt($student, $school, $assessment);

        foreach (array_keys(\App\Http\Controllers\School\CbtAdminConsoleController::TABS) as $tab) {
            $this->actingAs($owner)
                ->get(route('school.cbt.console.index', ['school' => $school->slug, 'tab' => $tab]))
                ->assertOk();
        }
    }

    public function test_a_student_cannot_reach_the_console(): void
    {
        [$school, $student] = $this->schoolWith('contwo');

        $this->actingAs($student)->get(route('school.cbt.console.index', ['school' => $school->slug]))
            ->assertForbidden();
    }

    /**
     * User directive 2026-08-05: "not all staff should have access to
     * admin backend. This is only for admin of the school to set."
     * class-teacher/subject-teacher hold assessment_hub.create/.update
     * (2026-08-02, for building their OWN scoped assessments) but must
     * not reach this school-wide admin console as a side effect.
     */
    public function test_a_class_teacher_with_create_and_update_cannot_reach_the_console(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'conteacher']));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);
        $teacher = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($teacher)->get(route('school.cbt.console.index', ['school' => $school->slug]))
            ->assertForbidden();
    }

    public function test_a_role_granted_export_via_role_management_can_reach_the_console(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'conexport']));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);
        $teacher = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $role->id, 'status' => 'active']);
        $permission = \App\Models\Permission::where('code', 'assessment_hub.export')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($teacher)->get(route('school.cbt.console.index', ['school' => $school->slug]))
            ->assertOk();
    }

    public function test_suspending_then_reinstating_gives_the_lost_time_back(): void
    {
        [$school, $student, $owner, $assessment] = $this->schoolWith('conthree');
        $session = $this->beginAttempt($student, $school, $assessment);

        $this->actingAs($owner)->post(route('school.cbt.console.suspend', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'reason' => 'Phone visible on desk.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $suspended = $session->fresh();
        $this->assertSame(CbtSession::STATUS_SUSPENDED, $suspended->status);
        $this->assertSame('Phone visible on desk.', $suspended->suspended_reason);
        $this->assertNotNull($suspended->suspended_at);
        $endsAtWhenSuspended = $suspended->ends_at->copy();
        // Pretend staff deliberated for 5 minutes before reinstating.
        $suspended->forceFill(['suspended_at' => now()->subMinutes(5)])->save();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.console.reinstate', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $back = $session->fresh();
        $this->assertSame(CbtSession::STATUS_IN_PROGRESS, $back->status);
        $this->assertNull($back->suspended_at);
        // The 5 minutes lost to suspension were added back, so the taker
        // is not punished for staff deliberation time.
        $this->assertEqualsWithDelta(300, $endsAtWhenSuspended->diffInSeconds($back->ends_at), 5);
        app(TenantManager::class)->forget();
    }

    public function test_a_suspended_attempt_has_no_score_and_cannot_be_suspended_twice(): void
    {
        [$school, $student, $owner, $assessment] = $this->schoolWith('confour');
        $session = $this->beginAttempt($student, $school, $assessment);

        $this->actingAs($owner)->post(route('school.cbt.console.suspend', ['school' => $school->slug, 'sessionId' => $session->id]));

        app(TenantManager::class)->use($school);
        // Suspension is deliberately NOT submission: no score is computed.
        $this->assertNull($session->fresh()->total_score);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.console.suspend', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertStatus(422);
    }

    public function test_force_submit_ends_and_grades_the_attempt(): void
    {
        [$school, $student, $owner, $assessment] = $this->schoolWith('confive');
        $session = $this->beginAttempt($student, $school, $assessment);

        $this->actingAs($owner)->post(route('school.cbt.console.force-submit', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertNotNull($fresh->submitted_at);
        // The only question is objective, so it grades straight through.
        $this->assertSame(CbtSession::STATUS_GRADED, $fresh->status);
        app(TenantManager::class)->forget();
    }

    public function test_pausing_an_assessment_then_resuming_extends_every_live_attempt(): void
    {
        [$school, $student, $owner, $assessment] = $this->schoolWith('consix');
        $session = $this->beginAttempt($student, $school, $assessment);

        $this->actingAs($owner)->post(route('school.cbt.console.pause', ['school' => $school->slug, 'assessmentId' => $assessment->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $paused = CbtAssessment::find($assessment->id);
        $this->assertNotNull($paused->paused_at);
        $endsAtBefore = $session->fresh()->ends_at->copy();
        $paused->forceFill(['paused_at' => now()->subMinutes(3)])->save();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.console.pause', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $this->assertNull(CbtAssessment::find($assessment->id)->paused_at);
        $this->assertEqualsWithDelta(180, $endsAtBefore->diffInSeconds($session->fresh()->ends_at), 5);
        app(TenantManager::class)->forget();
    }

    public function test_results_can_be_held_back_and_released(): void
    {
        [$school, $student, $owner, $assessment] = $this->schoolWith('conseven');

        $this->actingAs($owner)->post(route('school.cbt.console.release-results', ['school' => $school->slug, 'assessmentId' => $assessment->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        // Assessments default to released, so the first toggle holds them.
        $this->assertFalse(CbtAssessment::find($assessment->id)->results_released);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.console.release-results', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $this->assertTrue(CbtAssessment::find($assessment->id)->results_released);
        app(TenantManager::class)->forget();
    }
}
