<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
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
 * The per-assessment admin hub: its nine tabs, and the attempt actions
 * an exam officer running one sitting needs — grant extra time,
 * approve a break, delete an attempt, and dismiss a proctoring signal
 * that was a false alarm.
 */
class CbtAssessmentHubTest extends TestCase
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

    protected function scenario(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $student = User::factory()->create(['name' => 'Hub Student']);
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $assessment = CbtAssessment::create([
            'title' => 'Hub Paper', 'duration_minutes' => 30,
            'security_profile_id' => $basic->id, 'audience' => 'student',
        ]);
        $q = $this->makeTrueFalseQuestion('Q1.', true);
        CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $q->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        return [$school, $student, $owner, $assessment, $session];
    }

    public function test_every_hub_tab_renders(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('hubone');

        foreach (array_keys(\App\Http\Controllers\School\CbtAssessmentHubController::TABS) as $tab) {
            $this->actingAs($owner)
                ->get(route('school.cbt.hub.index', ['school' => $school->slug, 'assessmentId' => $assessment->id, 'tab' => $tab]))
                ->assertOk();
        }
    }

    public function test_a_student_cannot_reach_the_hub(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('hubtwo');

        $this->actingAs($student)
            ->get(route('school.cbt.hub.index', ['school' => $school->slug, 'assessmentId' => $assessment->id]))
            ->assertForbidden();
    }

    public function test_granting_extra_time_extends_only_that_takers_clock(): void
    {
        [$school, $student, $owner, $assessment, $session] = $this->scenario('hubthree');

        app(TenantManager::class)->use($school);
        $before = $session->fresh()->ends_at->copy();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.hub.grant-time', [
            'school' => $school->slug, 'assessmentId' => $assessment->id, 'sessionId' => $session->id,
        ]), ['minutes' => 15])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertEqualsWithDelta(900, $before->diffInSeconds($session->fresh()->ends_at), 5);
        app(TenantManager::class)->forget();
    }

    public function test_an_attempt_from_another_assessment_cannot_be_acted_on_through_this_hub(): void
    {
        [$school, $student, $owner, $assessment, $session] = $this->scenario('hubfour');

        app(TenantManager::class)->use($school);
        $other = CbtAssessment::create([
            'title' => 'Other Paper', 'duration_minutes' => 20,
            'security_profile_id' => $assessment->security_profile_id, 'audience' => 'student',
        ]);
        app(TenantManager::class)->forget();

        // The session belongs to $assessment, so acting on it through
        // $other's hub must 404 rather than quietly succeed.
        $this->actingAs($owner)->post(route('school.cbt.hub.grant-time', [
            'school' => $school->slug, 'assessmentId' => $other->id, 'sessionId' => $session->id,
        ]), ['minutes' => 5])->assertNotFound();
    }

    public function test_dismissing_a_proctoring_signal_refunds_its_risk_points(): void
    {
        [$school, $student, $owner, $assessment, $session] = $this->scenario('hubfive');

        // Three tab switches: 10 points each.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($student)->post(route('school.cbt.take.event', [
                'school' => $school->slug, 'sessionId' => $session->id,
            ]), ['type' => 'tab_switch']);
        }

        app(TenantManager::class)->use($school);
        $this->assertSame(30, $session->fresh()->risk_score);
        $event = CbtSecurityEvent::first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.hub.override-event', [
            'school' => $school->slug, 'assessmentId' => $assessment->id, 'eventId' => $event->id,
        ]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertSame(20, $fresh->risk_score, 'the dismissed signal should refund its own points');
        $this->assertSame('low', $fresh->risk_level, 'risk level must fall back below the medium threshold');
        $this->assertTrue(CbtSecurityEvent::find($event->id)->overridden);
        app(TenantManager::class)->forget();

        // Dismissing the same signal twice must not refund twice.
        $this->actingAs($owner)->post(route('school.cbt.hub.override-event', [
            'school' => $school->slug, 'assessmentId' => $assessment->id, 'eventId' => $event->id,
        ]))->assertStatus(422);
    }

    public function test_deleting_an_attempt_takes_its_events_with_it(): void
    {
        [$school, $student, $owner, $assessment, $session] = $this->scenario('hubsix');

        $this->actingAs($student)->post(route('school.cbt.take.event', [
            'school' => $school->slug, 'sessionId' => $session->id,
        ]), ['type' => 'tab_switch']);

        app(TenantManager::class)->use($school);
        $this->assertSame(1, CbtSecurityEvent::where('session_id', $session->id)->count());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete(route('school.cbt.hub.delete-attempt', [
            'school' => $school->slug, 'assessmentId' => $assessment->id, 'sessionId' => $session->id,
        ]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull(CbtSession::find($session->id));
        // Cascade must leave nothing orphaned.
        $this->assertSame(0, CbtSecurityEvent::where('session_id', $session->id)->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_break_can_be_approved_from_the_hub(): void
    {
        [$school, $student, $owner, $assessment, $session] = $this->scenario('hubseven');

        $this->actingAs($student)->post(route('school.cbt.take.break-request', [
            'school' => $school->slug, 'sessionId' => $session->id,
        ]), ['reason' => 'Water.'])->assertOk();

        $this->actingAs($owner)->post(route('school.cbt.hub.approve-break', [
            'school' => $school->slug, 'assessmentId' => $assessment->id, 'sessionId' => $session->id,
        ]), ['minutes' => 5])->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertFalse($fresh->break_requested);
        $this->assertTrue($fresh->isOnBreak());
        app(TenantManager::class)->forget();
    }

    /**
     * eligibleTakerIds() (the general case above's own eligible-count
     * source) only ever counts School\User takers — meaningless for an
     * applicant-audience assessment, which is scoped by
     * applicant_school_class_id instead. The overview tab should show
     * the real count of applicants in that class, not 0/everyone.
     */
    public function test_the_overview_tab_counts_eligible_applicants_for_an_applicant_audience_assessment(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'hubapplicant']));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);
        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $section = \App\Models\Tenant\Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $jss1 = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS1', 'sequence' => 1]);
        $jss2 = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS2', 'sequence' => 2]);
        $academicSession = \App\Models\Tenant\AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);

        foreach (['Ada', 'Bola', 'Chidi'] as $i => $name) {
            \App\Models\Tenant\Applicant::forceCreate([
                'name' => $name, 'phone' => '0801111000'.$i, 'admission_number' => 'APP/000'.$i,
                'session_id' => $academicSession->id, 'school_class_id' => $jss1->id,
                'account_status' => \App\Models\Tenant\Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
            ]);
        }
        \App\Models\Tenant\Applicant::forceCreate([
            'name' => 'Not JSS1', 'phone' => '08019999999', 'admission_number' => 'APP/0099',
            'session_id' => $academicSession->id, 'school_class_id' => $jss2->id,
            'account_status' => \App\Models\Tenant\Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
        ]);

        $assessment = CbtAssessment::create([
            'title' => 'JSS1 Entrance', 'duration_minutes' => 60, 'security_profile_id' => $basic->id,
            'audience' => CbtAssessment::AUDIENCE_APPLICANT, 'applicant_school_class_id' => $jss1->id,
        ]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)
            ->get(route('school.cbt.hub.index', ['school' => $school->slug, 'assessmentId' => $assessment->id]))
            ->assertOk();

        // Only the 3 JSS1 applicants count, not the JSS2 one — matched
        // against the exact table row the view renders, not a bare "3"
        // that could coincidentally appear anywhere else on the page.
        $this->assertMatchesRegularExpression(
            '/Eligible takers<\/td>\s*<td>\s*3\s*<\/td>/',
            $response->getContent()
        );
    }
}
