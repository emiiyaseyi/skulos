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
 * Assessment Security Profiles redesign (feedback list 8/7/2026, item
 * 18, in line with the LearnGuard reference plugin's own settings
 * catalog): real per-profile risk weights/thresholds, a genuine
 * auto-suspend score, break min/max minutes, and the 6 identity/wizard
 * fields the edit form silently never exposed.
 */
class CbtSecurityProfileGovernanceTest extends TestCase
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

    protected function schoolWithOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);
        $owner = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $owner];
    }

    protected function schoolWithSession(string $slug, string $profileName = 'Basic'): array
    {
        [$school, $owner] = $this->schoolWithOwner($slug);
        $student = User::factory()->create();
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $profile = CbtSecurityProfile::where('name', $profileName)->first();
        $paper = CbtAssessment::create(['title' => 'Quiz', 'duration_minutes' => 30, 'security_profile_id' => $profile->id, 'audience' => 'student']);
        $q = $this->makeTrueFalseQuestion('Q1', true);
        CbtAssessmentQuestion::create(['assessment_id' => $paper->id, 'question_id' => $q->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        return [$school, $student, $owner, $paper, $profile];
    }

    public function test_the_previously_unreachable_identity_fields_now_save(): void
    {
        [$school, $owner] = $this->schoolWithOwner('spgone');
        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $profile = CbtSecurityProfile::where('name', 'Basic')->first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.security-profiles.update', ['school' => $school->slug, 'profileId' => $profile->id]), [
            'tab_switch_action' => 'none',
            'require_audio_check' => '1', 'require_photo_id' => '1', 'require_face_match' => '1',
            'require_screen_share' => '1', 'require_environment_scan' => '1', 'snapshot_interval_seconds' => 20,
            'event_scores' => array_fill_keys(\App\Http\Controllers\School\CbtSecurityProfileController::eventTypes(), 5),
            'risk_medium_threshold' => 30, 'risk_high_threshold' => 60,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $profile->fresh();
        app(TenantManager::class)->forget();

        $this->assertTrue($fresh->require_audio_check);
        $this->assertTrue($fresh->require_photo_id);
        $this->assertTrue($fresh->require_face_match);
        $this->assertTrue($fresh->require_screen_share);
        $this->assertTrue($fresh->require_environment_scan);
        $this->assertSame(20, $fresh->snapshot_interval_seconds);
    }

    public function test_per_profile_event_weights_actually_change_the_resulting_risk_score(): void
    {
        [$school, $student, , $paper, $profile] = $this->schoolWithSession('spgtwo');
        app(TenantManager::class)->use($school);
        $profile->update(['event_scores' => ['tab_switch' => 99] + $profile->event_scores]);
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'type' => 'tab_switch',
        ])->assertOk();

        app(TenantManager::class)->use($school);
        $this->assertSame(99, $session->fresh()->risk_score, 'the profiles own custom weight (99) should be used, not the global default (10)');
        app(TenantManager::class)->forget();
    }

    public function test_a_profile_with_an_autosuspend_score_actually_suspends_the_session(): void
    {
        [$school, $student, , $paper, $profile] = $this->schoolWithSession('spgthree');
        app(TenantManager::class)->use($school);
        $profile->update(['autosuspend_score' => 5, 'event_scores' => ['tab_switch' => 10] + $profile->event_scores]);
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'type' => 'tab_switch',
        ])->assertOk();

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        app(TenantManager::class)->forget();

        $this->assertSame(CbtSession::STATUS_SUSPENDED, $fresh->status);
        $this->assertNull($fresh->suspended_by_user_id, 'a system-triggered auto-suspend has no staff actor');
        $this->assertNotNull($fresh->suspended_reason);
    }

    public function test_a_profile_with_no_autosuspend_score_never_auto_suspends(): void
    {
        [$school, $student, , $paper] = $this->schoolWithSession('spgfour');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        // Several tab switches, well past any real threshold — 'none'
        // tab_switch_action on Basic means no auto-submit either.
        foreach (range(1, 5) as $i) {
            $this->actingAs($student)->post(route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]), [
                'type' => 'tab_switch',
            ]);
        }

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        app(TenantManager::class)->forget();

        $this->assertSame(CbtSession::STATUS_IN_PROGRESS, $fresh->status, 'today\'s behavior is unchanged when autosuspend_score is left null');
    }

    public function test_a_break_approval_is_clamped_to_the_profiles_own_maximum(): void
    {
        [$school, $student, $owner, $paper, $profile] = $this->schoolWithSession('spgfive');
        app(TenantManager::class)->use($school);
        $profile->update(['break_max_minutes' => 5]);
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        $originalEndsAt = $session->ends_at->copy();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.break-request', ['school' => $school->slug, 'sessionId' => $session->id]), ['reason' => 'Need water.']);
        $this->actingAs($owner)->post(route('school.cbt.live-monitor.approve-break', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'minutes' => 20,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        app(TenantManager::class)->forget();

        $this->assertEquals($originalEndsAt->addMinutes(5)->timestamp, $fresh->ends_at->timestamp, 'clamped to the profiles own 5-minute maximum, not the requested 20');
    }
}
