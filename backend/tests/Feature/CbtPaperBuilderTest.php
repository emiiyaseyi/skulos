<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtPaper;
use App\Models\Tenant\CbtPaperQuestion;
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
class CbtPaperBuilderTest extends TestCase
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

    public function test_owner_can_build_a_paper_and_reorder_its_questions(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cpbtwo');

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $q1 = CbtQuestion::create(['type' => 'true_false', 'prompt' => 'Q1', 'correct_answer' => true, 'points' => 1]);
        $q2 = CbtQuestion::create(['type' => 'true_false', 'prompt' => 'Q2', 'correct_answer' => false, 'points' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.papers.store', ['school' => $school->slug]), [
            'title' => 'Mid Term Test', 'duration_minutes' => 30, 'security_profile_id' => $basic->id, 'audience' => 'student',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $paper = CbtPaper::first();
        $this->assertNotNull($paper);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.papers.questions.store', ['school' => $school->slug, 'paperId' => $paper->id]), ['question_id' => $q1->id]);
        $this->actingAs($owner)->post(route('school.cbt.papers.questions.store', ['school' => $school->slug, 'paperId' => $paper->id]), ['question_id' => $q2->id]);

        app(TenantManager::class)->use($school);
        $this->assertSame(2, CbtPaperQuestion::where('paper_id', $paper->id)->count());
        $firstPq = CbtPaperQuestion::where('paper_id', $paper->id)->where('question_id', $q1->id)->first();
        $this->assertSame(0, $firstPq->sequence);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.papers.questions.move', [
            'school' => $school->slug, 'paperId' => $paper->id, 'paperQuestionId' => $firstPq->id, 'direction' => 'down',
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
        $paper = CbtPaper::create(['title' => 'Quiz', 'duration_minutes' => 10, 'security_profile_id' => $basic->id, 'audience' => 'student']);
        $q1 = CbtQuestion::create(['type' => 'true_false', 'prompt' => 'Q1', 'correct_answer' => true, 'points' => 1]);
        $q2 = CbtQuestion::create(['type' => 'true_false', 'prompt' => 'Q2', 'correct_answer' => true, 'points' => 1]);
        $q3 = CbtQuestion::create(['type' => 'true_false', 'prompt' => 'Q3', 'correct_answer' => true, 'points' => 1]);
        $pq1 = CbtPaperQuestion::create(['paper_id' => $paper->id, 'question_id' => $q1->id, 'sequence' => 0]);
        $pq2 = CbtPaperQuestion::create(['paper_id' => $paper->id, 'question_id' => $q2->id, 'sequence' => 1]);
        $pq3 = CbtPaperQuestion::create(['paper_id' => $paper->id, 'question_id' => $q3->id, 'sequence' => 2]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete(route('school.cbt.papers.questions.destroy', [
            'school' => $school->slug, 'paperId' => $paper->id, 'paperQuestionId' => $pq2->id,
        ]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(0, $pq1->fresh()->sequence);
        $this->assertSame(1, $pq3->fresh()->sequence, 'the gap left by removing pq2 must close');
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_without_the_permission_cannot_reach_papers(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'cpbfour');
        $teacher = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'subject-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($teacher)->get(route('school.cbt.papers.index', ['school' => $school->slug]))
            ->assertForbidden();
    }
}
