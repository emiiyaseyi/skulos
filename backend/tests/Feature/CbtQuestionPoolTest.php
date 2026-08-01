<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtQuestionCategory;
use App\Models\Tenant\CbtQuestionPool;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\CbtSession;
use App\Models\Tenant\CbtSessionQuestion;
use App\Models\User;
use App\Services\Cbt\QuestionSetBuilder;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Random pools, subset drawing, shuffling, and the per-attempt frozen
 * question set that makes all three safe to resume and review.
 */
class CbtQuestionPoolTest extends TestCase
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

        $student = User::factory()->create();
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $assessment = CbtAssessment::create([
            'title' => 'Pooled Paper', 'duration_minutes' => 30,
            'security_profile_id' => $basic->id, 'audience' => 'student',
        ]);
        app(TenantManager::class)->forget();

        return [$school, $student, $owner, $assessment];
    }

    public function test_a_pool_draws_the_requested_number_from_its_category(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('poolone');

        app(TenantManager::class)->use($school);
        $category = CbtQuestionCategory::create(['name' => 'Algebra']);
        for ($i = 1; $i <= 10; $i++) {
            $q = $this->makeTrueFalseQuestion("Algebra {$i}.", true);
            $q->update(['category_id' => $category->id]);
        }
        CbtQuestionPool::create([
            'assessment_id' => $assessment->id, 'category_id' => $category->id,
            'draw_count' => 4, 'points_each' => 2,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        $given = CbtSessionQuestion::where('session_id', $session->id)->get();
        $this->assertCount(4, $given, 'the pool should draw exactly its draw_count');
        // Marks come from the pool, not the question's own default.
        $this->assertTrue($given->every(fn ($row) => (float) $row->points === 2.0));
        app(TenantManager::class)->forget();
    }

    public function test_two_takers_get_different_papers_from_the_same_pool(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('pooltwo');

        $second = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($second->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $category = CbtQuestionCategory::create(['name' => 'Wide pool']);
        for ($i = 1; $i <= 30; $i++) {
            $this->makeTrueFalseQuestion("Q{$i}.", true)->update(['category_id' => $category->id]);
        }
        CbtQuestionPool::create([
            'assessment_id' => $assessment->id, 'category_id' => $category->id,
            'draw_count' => 8, 'points_each' => 1,
        ]);
        app(TenantManager::class)->forget();

        foreach ([$student, $second] as $taker) {
            $this->actingAs($taker)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));
        }

        app(TenantManager::class)->use($school);
        $sessions = CbtSession::orderBy('id')->get();
        $this->assertCount(2, $sessions);
        $setA = CbtSessionQuestion::where('session_id', $sessions[0]->id)->pluck('question_id')->sort()->values()->all();
        $setB = CbtSessionQuestion::where('session_id', $sessions[1]->id)->pluck('question_id')->sort()->values()->all();
        $this->assertCount(8, $setA);
        $this->assertCount(8, $setB);
        // 8 drawn from 30 twice: identical sets would be a ~1-in-6-million
        // coincidence, so this genuinely proves independent draws.
        $this->assertNotSame($setA, $setB, 'two takers should not get the same drawn paper');
        app(TenantManager::class)->forget();
    }

    public function test_a_takers_frozen_set_never_changes_on_resume(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('poolthree');

        app(TenantManager::class)->use($school);
        $category = CbtQuestionCategory::create(['name' => 'Pool']);
        for ($i = 1; $i <= 20; $i++) {
            $this->makeTrueFalseQuestion("Q{$i}.", true)->update(['category_id' => $category->id]);
        }
        CbtQuestionPool::create([
            'assessment_id' => $assessment->id, 'category_id' => $category->id,
            'draw_count' => 5, 'points_each' => 1,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        $first = CbtSessionQuestion::where('session_id', $session->id)->orderBy('sequence')->pluck('question_id')->all();
        app(TenantManager::class)->forget();

        // Resume twice; the paper must be identical each time.
        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));
        $this->actingAs($student)->get(route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $session->id]));

        app(TenantManager::class)->use($school);
        $again = CbtSessionQuestion::where('session_id', $session->id)->orderBy('sequence')->pluck('question_id')->all();
        $this->assertSame($first, $again, 'a resumed attempt must not re-roll its questions');
        $this->assertSame(1, CbtSession::count(), 'resuming must not create a second attempt');
        app(TenantManager::class)->forget();
    }

    public function test_a_random_subset_always_keeps_mandatory_questions(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('poolfour');

        app(TenantManager::class)->use($school);
        // 10 fixed questions, 3 of which must always appear.
        $mandatoryIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $q = $this->makeTrueFalseQuestion("Q{$i}.", true);
            if ($i <= 3) {
                $q->update(['is_mandatory' => true]);
                $mandatoryIds[] = $q->id;
            }
            CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $q->id, 'sequence' => $i]);
        }
        // Ask for only 5 of the 10.
        CbtAssessment::where('id', $assessment->id)->update(['randomize' => true, 'num_questions' => 5]);
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        $given = CbtSessionQuestion::where('session_id', $session->id)->pluck('question_id')->all();
        $this->assertCount(5, $given);
        foreach ($mandatoryIds as $id) {
            $this->assertContains($id, $given, 'a question marked always-include must survive the subset draw');
        }
        app(TenantManager::class)->forget();
    }

    public function test_mandatory_questions_are_never_dropped_to_hit_a_smaller_count(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('poolfive');

        app(TenantManager::class)->use($school);
        // 4 mandatory questions but only 2 requested — the flag wins.
        for ($i = 1; $i <= 4; $i++) {
            $q = $this->makeTrueFalseQuestion("M{$i}.", true);
            $q->update(['is_mandatory' => true]);
            CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $q->id, 'sequence' => $i]);
        }
        CbtAssessment::where('id', $assessment->id)->update(['randomize' => true, 'num_questions' => 2]);
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        $this->assertSame(4, CbtSessionQuestion::where('session_id', $session->id)->count(),
            'always-include questions are not dropped to satisfy a smaller requested count');
        app(TenantManager::class)->forget();
    }

    public function test_a_pool_question_is_never_duplicated_by_a_fixed_one(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('poolsix');

        app(TenantManager::class)->use($school);
        $category = CbtQuestionCategory::create(['name' => 'Shared']);
        $questions = collect(range(1, 5))->map(function ($i) use ($category) {
            $q = $this->makeTrueFalseQuestion("S{$i}.", true);
            $q->update(['category_id' => $category->id]);

            return $q;
        });
        // One of the pool's own questions is ALSO attached as fixed.
        CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $questions[0]->id, 'sequence' => 0]);
        CbtQuestionPool::create([
            'assessment_id' => $assessment->id, 'category_id' => $category->id,
            'draw_count' => 4, 'points_each' => 1,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));

        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        $given = CbtSessionQuestion::where('session_id', $session->id)->pluck('question_id')->all();
        $this->assertSame(count($given), count(array_unique($given)), 'no question may appear twice on one paper');
        app(TenantManager::class)->forget();
    }

    public function test_grading_uses_the_takers_own_set_and_its_pool_marks(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('poolseven');

        app(TenantManager::class)->use($school);
        $category = CbtQuestionCategory::create(['name' => 'Marks']);
        for ($i = 1; $i <= 6; $i++) {
            $this->makeTrueFalseQuestion("Q{$i}.", true)->update(['category_id' => $category->id]);
        }
        // Drawn questions are worth 5 each, though the questions
        // themselves default to 1 mark.
        CbtQuestionPool::create([
            'assessment_id' => $assessment->id, 'category_id' => $category->id,
            'draw_count' => 3, 'points_each' => 5,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($student)->post(route('school.cbt.take.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]));
        app(TenantManager::class)->use($school);
        $session = CbtSession::first();
        $given = CbtSessionQuestion::with('question.answers')->where('session_id', $session->id)->get();
        app(TenantManager::class)->forget();

        // Answer the first one correctly.
        $first = $given->first();
        $trueId = $first->question->answers->firstWhere('answer_text', 'True')->id;
        $this->actingAs($student)->post(route('school.cbt.take.answer', ['school' => $school->slug, 'sessionId' => $session->id]), [
            'question_id' => $first->question_id, 'answer' => $trueId,
        ]);
        $this->actingAs($student)->post(route('school.cbt.take.submit', ['school' => $school->slug, 'sessionId' => $session->id]));

        app(TenantManager::class)->use($school);
        $fresh = $session->fresh();
        // 3 drawn questions at 5 marks each.
        $this->assertSame(15.0, (float) $fresh->max_score);
        // One right, worth the POOL's 5 marks rather than the question's own 1.
        $this->assertSame(5.0, (float) $fresh->total_score);
        app(TenantManager::class)->forget();
    }

    public function test_a_pool_larger_than_its_category_is_rejected(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('pooleight');

        app(TenantManager::class)->use($school);
        $category = CbtQuestionCategory::create(['name' => 'Thin']);
        $this->makeTrueFalseQuestion('Only one.', true)->update(['category_id' => $category->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.assessments.pools.store', [
            'school' => $school->slug, 'assessmentId' => $assessment->id,
        ]), ['category_id' => $category->id, 'draw_count' => 10, 'points_each' => 1])
            ->assertStatus(422);

        app(TenantManager::class)->use($school);
        $this->assertSame(0, CbtQuestionPool::count());
        app(TenantManager::class)->forget();
    }

    public function test_the_builder_is_deterministic_for_the_same_session(): void
    {
        [$school, $student, $owner, $assessment] = $this->scenario('poolnine');

        app(TenantManager::class)->use($school);
        $category = CbtQuestionCategory::create(['name' => 'Deterministic']);
        for ($i = 1; $i <= 20; $i++) {
            $this->makeTrueFalseQuestion("Q{$i}.", true)->update(['category_id' => $category->id]);
        }
        CbtQuestionPool::create([
            'assessment_id' => $assessment->id, 'category_id' => $category->id,
            'draw_count' => 6, 'points_each' => 1,
        ]);

        $builder = app(QuestionSetBuilder::class);
        $fresh = CbtAssessment::with(['assessmentQuestions.question', 'pools'])->find($assessment->id);

        // Same seed twice must give the same paper, so a re-run can be
        // reproduced and audited.
        $a = array_column($builder->pick($fresh, 4242), 'question_id');
        $b = array_column($builder->pick($fresh, 4242), 'question_id');
        $this->assertSame($a, $b);

        $c = array_column($builder->pick($fresh, 9999), 'question_id');
        $this->assertNotSame($a, $c, 'a different seed should give a different paper');
        app(TenantManager::class)->forget();
    }
}
