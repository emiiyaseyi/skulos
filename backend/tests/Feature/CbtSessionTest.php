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
 * SKULOS Assessment Hub, Phase 2b: starting a timed attempt, auto-saving
 * answers, submitting, auto-grading objective questions, and the
 * server-side auto-submit-on-timeout backstop.
 */
class CbtSessionTest extends TestCase
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

    protected function schoolWithStudentAndPaper(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $student = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $paper = CbtPaper::create(['title' => 'Quick Quiz', 'duration_minutes' => 20, 'security_profile_id' => $basic->id, 'audience' => 'student']);
        $mcq = CbtQuestion::create(['type' => 'multiple_choice_single', 'prompt' => '2+2?', 'options' => ['3', '4', '5'], 'correct_answer' => '4', 'points' => 2]);
        $essay = CbtQuestion::create(['type' => 'essay', 'prompt' => 'Explain gravity.', 'points' => 5]);
        CbtPaperQuestion::create(['paper_id' => $paper->id, 'question_id' => $mcq->id, 'sequence' => 0]);
        CbtPaperQuestion::create(['paper_id' => $paper->id, 'question_id' => $essay->id, 'sequence' => 1]);
        app(TenantManager::class)->forget();

        return [$school, $student, $paper, $mcq, $essay];
    }

    public function test_a_student_can_start_answer_and_submit_an_assessment(): void
    {
        [$school, $student, $paper, $mcq, $essay] = $this->schoolWithStudentAndPaper('csone');

        $this->actingAs($student)->get(route('school.cbt.take.available', ['school' => $school->slug]))
            ->assertOk()->assertSee('Quick Quiz');

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        $this->assertNotNull($session);
        $this->assertSame(CbtSession::STATUS_IN_PROGRESS, $session->status);
        $this->assertSame($student->id, $session->takeable_id);
        $this->assertSame(User::class, $session->takeable_type);
        app(TenantManager::class)->forget();

        // Resuming an already-in-progress attempt returns the SAME session, not a second one.
        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $this->assertSame(1, CbtSession::count());
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.answer', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'question_id' => $mcq->id, 'answer' => '4',
        ])->assertOk()->assertJson(['saved' => true]);

        $this->actingAs($student)->post(route('school.cbt.take.answer', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'question_id' => $essay->id, 'answer' => 'Gravity pulls objects together.',
        ]);

        $this->actingAs($student)->post(route('school.cbt.take.submit', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertNotNull($fresh->submitted_at);
        // Essay still ungraded -> session cannot be fully graded yet.
        $this->assertSame(CbtSession::STATUS_SUBMITTED, $fresh->status);
        $mcqResponse = $fresh->responses()->where('question_id', $mcq->id)->first();
        $this->assertTrue($mcqResponse->is_correct);
        $this->assertSame(2.0, (float) $mcqResponse->score_awarded);
        $this->assertSame('auto_graded', $mcqResponse->status);
        $essayResponse = $fresh->responses()->where('question_id', $essay->id)->first();
        $this->assertSame('ungraded', $essayResponse->status);
        app(TenantManager::class)->forget();
    }

    public function test_a_fully_objective_paper_finalizes_to_graded_immediately_on_submit(): void
    {
        [$school, $student, $paper, $mcq, $essay] = $this->schoolWithStudentAndPaper('cstwo');

        app(TenantManager::class)->use($school);
        // Drop the essay question, leaving only the objective one.
        CbtPaperQuestion::where('paper_id', $paper->id)->where('question_id', $essay->id)->delete();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.answer', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'question_id' => $mcq->id, 'answer' => '4',
        ]);
        $this->actingAs($student)->post(route('school.cbt.take.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertSame(CbtSession::STATUS_GRADED, $fresh->status);
        $this->assertSame(2.0, (float) $fresh->total_score);
        $this->assertSame(2.0, (float) $fresh->max_score);
        app(TenantManager::class)->forget();
    }

    public function test_another_students_session_cannot_be_viewed_or_answered(): void
    {
        [$school, $student1, $paper] = $this->schoolWithStudentAndPaper('csthree');
        $student2 = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student2->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($student1)->post(route('school.cbt.take.start', ['school' => $school->slug, 'paperId' => $paper->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        app(TenantManager::class)->forget();

        $this->actingAs($student2)->get(route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $session->id]))
            ->assertForbidden();
    }

    public function test_the_scheduled_command_auto_submits_expired_sessions(): void
    {
        [$school, $student, $paper, $mcq] = $this->schoolWithStudentAndPaper('csfour');

        app(TenantManager::class)->use($school);
        $session = CbtSession::create([
            'paper_id' => $paper->id, 'takeable_type' => User::class, 'takeable_id' => $student->id,
            'started_at' => now()->subMinutes(40), 'ends_at' => now()->subMinutes(20),
        ]);
        app(TenantManager::class)->forget();

        $this->artisan('cbt:auto-submit-expired-sessions')->assertSuccessful();

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        $this->assertSame(CbtSession::STATUS_AUTO_SUBMITTED, $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
        app(TenantManager::class)->forget();
    }
}
