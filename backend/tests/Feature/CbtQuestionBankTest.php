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
        $question = CbtQuestion::with('answers')->first();
        $this->assertNotNull($question);
        $this->assertSame(['3', '4', '5'], $question->answers->pluck('answer_text')->all());
        $correct = $question->answers->firstWhere('answer_text', '4');
        $this->assertSame(1.0, $correct->fraction);
        // Grading is now a FRACTION of the marks, not a bool, so partial
        // credit is expressible — a single-choice question is still all
        // or nothing.
        $this->assertSame(1.0, $question->gradeFraction($correct->id));
        $this->assertSame(0.0, $question->gradeFraction($question->answers->firstWhere('answer_text', '3')->id));
        app(TenantManager::class)->forget();
    }

    public function test_a_multi_answer_question_awards_partial_credit(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbsix');

        $this->actingAs($owner)->post(route('school.cbt.questions.store', ['school' => $school->slug]), [
            'type' => 'multiple_choice_multiple',
            'prompt' => 'Which are prime?',
            'points' => 4,
            'mc_options' => "2\n3\n4\n9",
            'mc_correct_multiple_raw' => "2\n3",
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $question = CbtQuestion::with('answers')->first();
        $two = $question->answers->firstWhere('answer_text', '2')->id;
        $three = $question->answers->firstWhere('answer_text', '3')->id;
        $four = $question->answers->firstWhere('answer_text', '4')->id;

        $this->assertSame(1.0, $question->gradeFraction([$two, $three]));   // both correct -> full marks
        $this->assertSame(0.5, $question->gradeFraction([$two]));            // one of two -> half
        $this->assertSame(0.0, $question->gradeFraction([$two, $four]));     // one right, one wrong -> cancels out
        app(TenantManager::class)->forget();
    }

    public function test_a_matching_question_parses_pairs_and_grades_correctly(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbtwo');

        $this->actingAs($owner)->post(route('school.cbt.questions.store', ['school' => $school->slug]), [
            'type' => 'matching',
            'prompt' => 'Match the capital to the country.',
            'points' => 4,
            'matching_pairs' => "Nigeria = Abuja\nGhana = Accra",
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $question = CbtQuestion::with('answers')->first();
        $this->assertSame(['Nigeria', 'Ghana'], $question->answers->pluck('answer_text')->all());
        $this->assertSame(['Abuja', 'Accra'], $question->answers->pluck('feedback')->all());

        $nigeria = $question->answers->firstWhere('answer_text', 'Nigeria')->id;
        $ghana = $question->answers->firstWhere('answer_text', 'Ghana')->id;

        $this->assertSame(1.0, $question->gradeFraction([$nigeria => 'Abuja', $ghana => 'Accra']));
        $this->assertSame(0.5, $question->gradeFraction([$nigeria => 'Abuja', $ghana => 'Abuja']));
        $this->assertSame(0.0, $question->gradeFraction([$nigeria => 'Accra', $ghana => 'Abuja']));
        app(TenantManager::class)->forget();
    }

    public function test_an_essay_question_is_never_auto_graded(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbthree');

        $this->actingAs($owner)->post(route('school.cbt.questions.store', ['school' => $school->slug]), [
            'type' => 'essay', 'prompt' => 'Describe photosynthesis.', 'points' => 10,
            'marking_guidance' => 'Look for light, water and carbon dioxide.',
        ]);

        app(TenantManager::class)->use($school);
        $question = CbtQuestion::with('answers')->first();
        $this->assertFalse($question->isObjective());
        $this->assertCount(0, $question->answers);
        $this->assertNull($question->gradeFraction('Any answer at all'));
        $this->assertSame('Look for light, water and carbon dioxide.', $question->marking_guidance);
        app(TenantManager::class)->forget();
    }

    public function test_a_short_answer_question_accepts_several_spellings_and_honours_case_sensitivity(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbseven');

        $this->actingAs($owner)->post(route('school.cbt.questions.store', ['school' => $school->slug]), [
            'type' => 'short_answer',
            'prompt' => 'Capital of Nigeria?',
            'points' => 1,
            'short_answer_correct' => "Abuja\nAbuja FCT",
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $question = CbtQuestion::with('answers')->first();
        $this->assertCount(2, $question->answers);
        $this->assertSame(1.0, $question->gradeFraction('Abuja'));
        $this->assertSame(1.0, $question->gradeFraction('Abuja FCT'));
        // Case-insensitive by default (case_sensitive was not ticked).
        $this->assertSame(1.0, $question->gradeFraction('abuja'));
        $this->assertSame(0.0, $question->gradeFraction('Lagos'));
        app(TenantManager::class)->forget();
    }

    public function test_bulk_deactivate_and_delete(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbfour');

        app(TenantManager::class)->use($school);
        $q1 = $this->makeTrueFalseQuestion('The sky is blue.', true);
        $q2 = $this->makeTrueFalseQuestion('The sky is green.', false);
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

    public function test_questions_can_be_filtered_by_category_and_bulk_moved(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbeight');

        $this->actingAs($owner)->post(route('school.cbt.questions.categories.store', ['school' => $school->slug]), [
            'name' => 'Algebra',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $categoryId = \App\Models\Tenant\CbtQuestionCategory::first()->id;
        $q1 = $this->makeTrueFalseQuestion('Uncategorised one.', true);
        $q2 = $this->makeTrueFalseQuestion('Uncategorised two.', true);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.questions.bulk-action', ['school' => $school->slug]), [
            'question_ids' => [$q1->id, $q2->id],
            'action' => 'move',
            'target_category_id' => $categoryId,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(2, CbtQuestion::where('category_id', $categoryId)->count());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)
            ->get(route('school.cbt.questions.index', ['school' => $school->slug, 'cat' => $categoryId]))
            ->assertOk()->assertSee('Uncategorised one.');
    }

    public function test_a_bulk_mark_change_applies_to_every_selected_question(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbnine');

        app(TenantManager::class)->use($school);
        $q1 = $this->makeTrueFalseQuestion('A.', true);
        $q2 = $this->makeTrueFalseQuestion('B.', true);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.cbt.questions.bulk-action', ['school' => $school->slug]), [
            'question_ids' => [$q1->id, $q2->id],
            'action' => 'change_mark',
            'new_mark' => 5,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(2, CbtQuestion::where('points', 5)->count());
        app(TenantManager::class)->forget();
    }

    /**
     * Round-trips the bank through each format. This is the piece Moodle
     * itself supplies to the reference plugin (its qformat_* plugins);
     * SKULOS is not Moodle, so the parsers are ours and need real
     * coverage.
     */
    public function test_questions_round_trip_through_every_import_export_format(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbten');

        app(TenantManager::class)->use($school);
        $this->makeChoiceQuestion('Which is prime?', ['4', '7', '9'], ['7'], 2);
        $this->makeTrueFalseQuestion('Water boils at 100C at sea level.', true);
        app(TenantManager::class)->forget();

        // Export EVERY format first, from the same 2-question bank —
        // exporting as we go would compound (each export would include
        // whatever the previous format just imported).
        $exports = [];
        foreach (array_keys(\App\Services\Cbt\QuestionPortabilityService::EXPORT_FORMATS) as $format) {
            $export = $this->actingAs($owner)
                ->get(route('school.cbt.questions.export', ['school' => $school->slug, 'format' => $format]));
            $export->assertOk();
            $exports[$format] = $export->getContent();
            $this->assertStringContainsString('Which is prime?', $exports[$format], "export failed for {$format}");
        }

        foreach ($exports as $format => $body) {
            $this->actingAs($owner)->post(route('school.cbt.questions.import', ['school' => $school->slug]), [
                'format' => $format,
                'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent("bank.{$format}", $body),
            ])->assertRedirect();
        }

        app(TenantManager::class)->use($school);
        // 2 originals + 2 re-imported per format across 4 formats.
        $this->assertSame(10, CbtQuestion::count());
        $reimported = CbtQuestion::where('prompt', 'Which is prime?')->with('answers')->get();
        $this->assertCount(5, $reimported);
        foreach ($reimported as $question) {
            // Every copy survived with its options AND kept "7" as the
            // correct one, so the round trip preserved gradability, not
            // just the question text.
            $this->assertSame(['4', '7', '9'], $question->answers->pluck('answer_text')->all());
            $this->assertSame('7', $question->answers->firstWhere('fraction', '>', 0)->answer_text);
        }
        app(TenantManager::class)->forget();
    }

    /**
     * Aiken, short-answer text and essay text are import-only formats
     * the reference repo supports; each has its own parser here since
     * Moodle's own qformat plugins are not available to SKULOS.
     */
    public function test_aiken_short_answer_and_essay_text_formats_all_import(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbeleven');

        $files = [
            'aiken' => "What color is the sky?\nA) Red\nB) Blue\nC) Green\nANSWER: B\n",
            'shortanswer' => "Capital of Nigeria?\nAnswer: Abuja\nAnswer: Abuja FCT\nCase: sensitive\nMarks: 2\n",
            'essay' => "Describe the water cycle.\nMark: 5\nAnswer guide: Evaporation, condensation, precipitation.\n",
        ];

        foreach ($files as $format => $body) {
            $this->actingAs($owner)->post(route('school.cbt.questions.import', ['school' => $school->slug]), [
                'format' => $format,
                'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent("in.txt", $body),
            ])->assertRedirect();
        }

        app(TenantManager::class)->use($school);
        $aiken = CbtQuestion::where('prompt', 'What color is the sky?')->with('answers')->first();
        $this->assertSame('multiple_choice_single', $aiken->type);
        $this->assertCount(3, $aiken->answers);
        $this->assertSame('Blue', $aiken->answers->firstWhere('fraction', 1.0)->answer_text);

        $short = CbtQuestion::where('prompt', 'Capital of Nigeria?')->with('answers')->first();
        $this->assertSame('short_answer', $short->type);
        $this->assertCount(2, $short->answers);
        $this->assertTrue($short->case_sensitive);
        $this->assertSame(2.0, (float) $short->points);
        // Case-sensitive was set, so the wrong casing must now score zero.
        $this->assertSame(1.0, $short->gradeFraction('Abuja'));
        $this->assertSame(0.0, $short->gradeFraction('abuja'));

        $essay = CbtQuestion::where('prompt', 'Describe the water cycle.')->with('answers')->first();
        $this->assertSame('essay', $essay->type);
        $this->assertCount(0, $essay->answers);
        $this->assertSame(5.0, (float) $essay->points);
        $this->assertStringContainsString('Evaporation', $essay->marking_guidance);
        app(TenantManager::class)->forget();
    }

    public function test_a_dry_run_import_reports_what_would_land_without_saving_anything(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbtwelve');

        $csv = "type,question,a1,a2,a3,a4,correct,marks,case_sensitive,marking_guide\n"
            ."multichoice,What is 2+2?,3,4,5,,B,1,,\n"
            ."truefalse,The sky is blue.,,,,,true,1,,\n"
            .",,,,,,,,,\n"; // deliberately malformed: no type, no question

        $this->actingAs($owner)->post(route('school.cbt.questions.import', ['school' => $school->slug]), [
            'format' => 'csv',
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('bank.csv', $csv),
            'dry_run' => '1',
        ])->assertRedirect()->assertSessionHas('import_preview');

        app(TenantManager::class)->use($school);
        $this->assertSame(0, CbtQuestion::count(), 'a dry run must not write anything');
        app(TenantManager::class)->forget();

        // The same file without dry_run does land, and skips the bad row.
        $this->actingAs($owner)->post(route('school.cbt.questions.import', ['school' => $school->slug]), [
            'format' => 'csv',
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('bank.csv', $csv),
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(2, CbtQuestion::count());
        $mc = CbtQuestion::where('prompt', 'What is 2+2?')->with('answers')->first();
        // "correct = B" means the SECOND option, not the literal text "B".
        $this->assertSame('4', $mc->answers->firstWhere('fraction', 1.0)->answer_text);
        app(TenantManager::class)->forget();
    }

    public function test_questions_can_be_imported_by_pasting_text_instead_of_uploading_a_file(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbfourteen');

        // Deliberately CRLF, as anything pasted from Windows Notepad or
        // Excel would be — the parsers split on \n and would otherwise
        // leave a stray \r on every value.
        $pasted = "What color is the sky?\r\nA) Red\r\nB) Blue\r\nC) Green\r\nANSWER: B\r\n";

        $this->actingAs($owner)->post(route('school.cbt.questions.import', ['school' => $school->slug]), [
            'format' => 'aiken',
            'pasted_text' => $pasted,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $question = CbtQuestion::with('answers')->first();
        $this->assertNotNull($question);
        $this->assertSame('What color is the sky?', $question->prompt);
        $this->assertSame(['Red', 'Blue', 'Green'], $question->answers->pluck('answer_text')->all());
        $this->assertSame('Blue', $question->answers->firstWhere('fraction', 1.0)->answer_text);
        app(TenantManager::class)->forget();
    }

    public function test_an_import_with_neither_a_file_nor_pasted_text_is_rejected(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbfifteen');

        $this->actingAs($owner)->post(route('school.cbt.questions.import', ['school' => $school->slug]), [
            'format' => 'csv',
        ])->assertSessionHasErrors('file');

        app(TenantManager::class)->use($school);
        $this->assertSame(0, CbtQuestion::count());
        app(TenantManager::class)->forget();
    }

    public function test_the_import_screen_renders(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbimport');

        $this->actingAs($owner)
            ->get(route('school.cbt.questions.import.form', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Import Questions')
            ->assertSee('or paste directly');
    }

    public function test_every_format_offers_a_downloadable_sample_template(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cqbthirteen');

        foreach (array_keys(\App\Services\Cbt\QuestionPortabilityService::IMPORT_FORMATS) as $format) {
            $response = $this->actingAs($owner)
                ->get(route('school.cbt.questions.import.template', ['school' => $school->slug, 'format' => $format]));
            $response->assertOk();
            $this->assertNotEmpty($response->getContent(), "no template for {$format}");
        }
    }

    /**
     * A student has assessment_hub.view (to see/take their own assigned
     * assessments) but never .create — the admin question bank shows
     * every question's correct answer, so this is a real security
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
