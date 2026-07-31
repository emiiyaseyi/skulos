<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtQuestion;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SKULOS Assessment Hub, Phase 2a: the school's own question bank, CRUD
 * + bulk actions, each question type's answer shape.
 */
class CbtQuestionBankTest extends TestCase
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

    public function test_owner_can_create_a_multiple_choice_single_question(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbone');

        $this->actingAs($owner)->post(route('school.cbt.questions.store', ['school' => $school->slug]), [
            'type' => 'multiple_choice_single',
            'prompt' => 'What is 2+2?',
            'points' => 2,
            'mc_options' => "3\n4\n5",
            'mc_correct_single' => '4',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $question = CbtQuestion::first();
        $this->assertNotNull($question);
        $this->assertSame(['3', '4', '5'], $question->options);
        $this->assertSame('4', $question->correct_answer);
        $this->assertTrue($question->gradeAnswer('4'));
        $this->assertFalse($question->gradeAnswer('3'));
        app(TenantManager::class)->forget();
    }

    public function test_a_matching_question_parses_pairs_and_grades_correctly(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbtwo');

        $this->actingAs($owner)->post(route('school.cbt.questions.store', ['school' => $school->slug]), [
            'type' => 'matching',
            'prompt' => 'Match the capital to the country.',
            'points' => 4,
            'matching_left' => "Nigeria\nGhana",
            'matching_right' => "Accra\nAbuja",
            'matching_pairs' => "0=1\n1=0",
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $question = CbtQuestion::first();
        $this->assertSame(['left' => ['Nigeria', 'Ghana'], 'right' => ['Accra', 'Abuja']], $question->options);
        $this->assertSame(['0' => '1', '1' => '0'], $question->correct_answer);
        $this->assertTrue($question->gradeAnswer(['0' => '1', '1' => '0']));
        $this->assertFalse($question->gradeAnswer(['0' => '0', '1' => '1']));
        app(TenantManager::class)->forget();
    }

    public function test_an_essay_question_is_never_auto_graded(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbthree');

        $this->actingAs($owner)->post(route('school.cbt.questions.store', ['school' => $school->slug]), [
            'type' => 'essay', 'prompt' => 'Describe photosynthesis.', 'points' => 10,
        ]);

        app(TenantManager::class)->use($school);
        $question = CbtQuestion::first();
        $this->assertFalse($question->isObjective());
        $this->assertNull($question->gradeAnswer('Any answer at all'));
        app(TenantManager::class)->forget();
    }

    public function test_bulk_deactivate_and_delete(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbfour');

        app(TenantManager::class)->use($school);
        $q1 = CbtQuestion::create(['type' => 'true_false', 'prompt' => 'The sky is blue.', 'correct_answer' => true, 'points' => 1]);
        $q2 = CbtQuestion::create(['type' => 'true_false', 'prompt' => 'The sky is green.', 'correct_answer' => false, 'points' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.questions.bulk-action', ['school' => $school->slug]), [
            'question_ids' => [$q1->id, $q2->id],
            'action' => 'deactivate',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(0, CbtQuestion::where('is_active', true)->count());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.questions.bulk-action', ['school' => $school->slug]), [
            'question_ids' => [$q1->id],
            'action' => 'delete',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(1, CbtQuestion::count());
        app(TenantManager::class)->forget();
    }

    /**
     * A student has assessment_hub.view (to see/take their own assigned
     * assessments, Phase 2b) but never .create — the admin question bank
     * shows every question's correct answer, so this is a real security
     * boundary, not just an access-control formality.
     */
    public function test_a_student_cannot_reach_the_admin_question_bank(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'cqbfive');
        $student = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($student)->get(route('school.cbt.questions.index', ['school' => $school->slug]))
            ->assertForbidden();
    }
}
