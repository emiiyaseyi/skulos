<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
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
 * SKULOS Assessment Hub, Phase 2a: assembling a timed paper from the
 * question bank, reordering, and the seeded security-profile catalog.
 */
class CbtAssessmentBuilderTest extends TestCase
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

    protected function schoolWithMember(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_the_four_security_profiles_seed_with_maximum_as_the_only_camera_tier(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cpbone');

        $this->actingAs($owner)->get(route('school.cbt.security-profiles.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('Basic')->assertSee('Medium')->assertSee('High')->assertSee('Maximum');

        app(TenantManager::class)->use($school);
        $this->assertSame(4, CbtSecurityProfile::count());
        $this->assertFalse(CbtSecurityProfile::where('name', 'Basic')->value('require_camera_check'));
        $this->assertTrue(CbtSecurityProfile::where('name', 'Maximum')->value('require_camera_check'));
        app(TenantManager::class)->forget();
    }

    /**
     * User directive 2026-08-05: "teachers should not be having access
     * to the security profiles." A subject teacher holds
     * assessment_hub.create/.update (2026-08-02) for building their own
     * assessments, but security profiles are a school-wide proctoring
     * policy, gated on the separate .export "blanket access" signal.
     */
    public function test_a_subject_teacher_cannot_reach_security_profiles(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'cpbsecoff');

        $this->actingAs($teacher)->get(route('school.cbt.security-profiles.index', ['school' => $school->slug]))
            ->assertForbidden();
    }

    public function test_owner_can_build_an_assessment_and_reorder_its_questions(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cpbtwo');

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $q1 = $this->makeTrueFalseQuestion('Q1', true);
        $q2 = $this->makeTrueFalseQuestion('Q2', false);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.assessments.store', ['school' => $school->slug]), [
            'title' => 'Mid Term Test', 'duration_minutes' => 30, 'security_profile_id' => $basic->id, 'audience' => 'student',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $paper = CbtAssessment::first();
        $this->assertNotNull($paper);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.assessments.questions.store', ['school' => $school->slug, 'assessmentId' => $paper->id]), ['question_id' => $q1->id]);
        $this->actingAs($owner)->post(route('school.cbt.assessments.questions.store', ['school' => $school->slug, 'assessmentId' => $paper->id]), ['question_id' => $q2->id]);

        app(TenantManager::class)->use($school);
        $this->assertSame(2, CbtAssessmentQuestion::where('assessment_id', $paper->id)->count());
        $firstPq = CbtAssessmentQuestion::where('assessment_id', $paper->id)->where('question_id', $q1->id)->first();
        $this->assertSame(0, $firstPq->sequence);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.assessments.questions.move', [
            'school' => $school->slug, 'assessmentId' => $paper->id, 'assessmentQuestionId' => $firstPq->id, 'direction' => 'down',
        ]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(1, $firstPq->fresh()->sequence, 'moving q1 down swaps it past q2');
        app(TenantManager::class)->forget();
    }

    public function test_removing_a_question_closes_the_sequence_gap(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cpbthree');

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $paper = CbtAssessment::create(['title' => 'Quiz', 'duration_minutes' => 10, 'security_profile_id' => $basic->id, 'audience' => 'student']);
        $q1 = $this->makeTrueFalseQuestion('Q1', true);
        $q2 = $this->makeTrueFalseQuestion('Q2', true);
        $q3 = $this->makeTrueFalseQuestion('Q3', true);
        $pq1 = CbtAssessmentQuestion::create(['assessment_id' => $paper->id, 'question_id' => $q1->id, 'sequence' => 0]);
        $pq2 = CbtAssessmentQuestion::create(['assessment_id' => $paper->id, 'question_id' => $q2->id, 'sequence' => 1]);
        $pq3 = CbtAssessmentQuestion::create(['assessment_id' => $paper->id, 'question_id' => $q3->id, 'sequence' => 2]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete(route('school.cbt.assessments.questions.destroy', [
            'school' => $school->slug, 'assessmentId' => $paper->id, 'assessmentQuestionId' => $pq2->id,
        ]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(0, $pq1->fresh()->sequence);
        $this->assertSame(1, $pq3->fresh()->sequence, 'the gap left by removing pq2 must close');
        app(TenantManager::class)->forget();
    }

    public function test_a_student_cannot_reach_the_admin_assessments_screen(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'cpbfour');
        $student = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($student)->get(route('school.cbt.assessments.index', ['school' => $school->slug]))
            ->assertForbidden();
    }
}
