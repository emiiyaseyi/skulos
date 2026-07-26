<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Score;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Results entry (user request, built 2026-07-09/2026-07-10): a teacher
 * picks a term/class/subject they teach and enters scores per assessment
 * component in one table. Report cards already read straight from
 * `Score` rows via ResultService, so these tests focus on who can reach
 * which class/subject and that saved rows land correctly.
 */
class ExaminationScreenTest extends TestCase
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
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    protected function seedAcademics(School $school): array
    {
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();

        $components = [
            AssessmentComponent::create(['name' => 'First CA', 'max_score' => 20, 'sequence' => 1]),
            AssessmentComponent::create(['name' => 'Second CA', 'max_score' => 20, 'sequence' => 2]),
            AssessmentComponent::create(['name' => 'Exam', 'max_score' => 60, 'sequence' => 3]),
        ];

        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);
        $bode = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade', 'current_class_arm_id' => $classArm->id]);

        app(TenantManager::class)->forget();

        return compact('classArm', 'term', 'subject', 'components', 'ada', 'bode');
    }

    protected function attachTeacher(School $school, User $user, int $classArmId, int $subjectId): void
    {
        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'T1', 'first_name' => 'Tade', 'last_name' => 'Teacher', 'user_id' => $user->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subjectId, 'class_arm_id' => $classArmId]);
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_sees_only_their_assigned_class_and_subject(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examone');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations")
            ->assertOk()
            ->assertSee('JSS 1 A: Mathematics');
    }

    /**
     * User request 2026-07-25: "live total/grade calc, 5s autosave,
     * auto-suggested remark" on this screen. The recalculation itself is
     * client side JS (not exercisable here), but the page must ship the
     * real grading/comment bands as JSON and the DOM hooks that JS reads,
     * against the same bands the server itself already uses.
     */
    public function test_the_score_entry_page_ships_grading_and_comment_bands_for_live_recalculation(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examlive');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);

        app(TenantManager::class)->use($school);
        $scale = \App\Models\Tenant\GradingScale::create(['name' => 'Standard', 'is_default' => true]);
        \App\Models\Tenant\GradingBand::create(['grading_scale_id' => $scale->id, 'min_score' => 70, 'max_score' => 100, 'grade' => 'A']);
        \App\Models\Tenant\Comment::create(['min_score' => 70, 'max_score' => 100, 'text' => 'Excellent performance.', 'is_active' => true]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations?pair={$f['classArm']->id}:{$f['subject']->id}");

        $response->assertOk()
            ->assertSee('"grade":"A"', false)
            ->assertSee('Excellent performance.')
            ->assertSee('js-score-input', false)
            ->assertSee('js-total', false)
            ->assertSee('js-remark', false)
            ->assertSee('autosaveStatus', false)
            ->assertSee('scoresForm', false);
    }

    /**
     * User request 2026-07-26: "on/off toggle for the score entry remark
     * everywhere it appears." Turning the Report Settings toggle off must
     * suppress both the server prefilled suggestion and the comment bank
     * JSON feed the client side re-suggestion reads, while the unrelated
     * live grade calc (grading bands) keeps working regardless.
     */
    public function test_the_suggested_remark_can_be_turned_off_and_the_grade_calc_still_works(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examnoremark');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        app(TenantManager::class)->use($school);
        $scale = \App\Models\Tenant\GradingScale::create(['name' => 'Standard', 'is_default' => true]);
        \App\Models\Tenant\GradingBand::create(['grading_scale_id' => $scale->id, 'min_score' => 70, 'max_score' => 100, 'grade' => 'A']);
        \App\Models\Tenant\Comment::create(['min_score' => 70, 'max_score' => 100, 'text' => 'Excellent performance.', 'is_active' => true]);
        \App\Models\Tenant\Score::create([
            'student_id' => $f['ada']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id,
            'term_id' => $f['term']->id, 'assessment_component_id' => $exam->id, 'score' => 80,
        ]);
        \App\Models\Tenant\Setting::put(\App\Http\Controllers\School\ReportCardController::SETTING_SUGGESTED_REMARK_ENABLED, false);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations?pair={$f['classArm']->id}:{$f['subject']->id}");

        $response->assertOk()
            ->assertSee('"grade":"A"', false)
            ->assertDontSee('Excellent performance.');
    }

    public function test_a_teacher_can_save_scores_for_their_assigned_class_and_subject(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examtwo');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [
                $f['ada']->id => [$first->id => 18, $second->id => 16, $exam->id => 52],
                $f['bode']->id => [$first->id => 10, $second->id => 10, $exam->id => 30],
            ],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(6, Score::count());
        $adaTotal = Score::where('student_id', $f['ada']->id)->sum('score');
        app(TenantManager::class)->forget();
        $this->assertEquals(86, $adaTotal);
    }

    public function test_saving_again_updates_existing_scores_instead_of_duplicating(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examthree');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        $payload = [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$first->id => 10, $second->id => 10, $exam->id => 30]],
        ];
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", $payload);

        $payload['scores'][$f['ada']->id][$first->id] = 20;
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", $payload);

        app(TenantManager::class)->use($school);
        $this->assertSame(3, Score::count());
        $updated = Score::where('student_id', $f['ada']->id)->where('assessment_component_id', $first->id)->first();
        app(TenantManager::class)->forget();
        $this->assertEquals(20, $updated->score);
    }

    public function test_a_teacher_cannot_save_scores_for_a_class_or_subject_they_do_not_teach(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examfour');
        $f = $this->seedAcademics($school);
        // Deliberately no attachTeacher() call: this teacher has no TeachingAssignment at all.

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$f['components'][0]->id => 15]],
        ])->assertForbidden();

        app(TenantManager::class)->use($school);
        $this->assertSame(0, Score::count());
        app(TenantManager::class)->forget();
    }

    public function test_a_score_above_the_components_max_is_rejected_and_nothing_is_saved(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examfive');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [
                $f['ada']->id => [$first->id => 999, $second->id => 10, $exam->id => 30],
            ],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(0, Score::count());
        app(TenantManager::class)->forget();
    }

    public function test_a_school_owner_can_save_scores_without_any_teaching_assignment(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'examsix');
        $f = $this->seedAcademics($school);
        [$first, $second, $exam] = $f['components'];

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations")
            ->assertOk()->assertSee('JSS 1 A: Mathematics');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$first->id => 18, $second->id => 16, $exam->id => 52]],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(3, Score::count());
        app(TenantManager::class)->forget();
    }

    /**
     * Regression test for a 2026-07-10 bug caught only by a live smoke
     * test, not the suite: allowedPairs() compared class_arm_id/subject_id
     * with strict === against the GET query string's (int) cast values,
     * but the pairs themselves were left as whatever type Eloquent handed
     * back, so the match silently failed on a real HTTP request even
     * though the direct save() POST worked fine. Revisiting the page with
     * ?pair=... afterwards must actually show the entry table with the
     * scores prefilled, not fall back to "choose a class and subject".
     */
    public function test_revisiting_with_a_pair_in_the_query_string_prefills_saved_scores(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'exameight');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$first->id => 18, $second->id => 16, $exam->id => 52]],
        ]);

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations?pair={$f['classArm']->id}:{$f['subject']->id}");

        $response->assertOk()
            ->assertSee('Ada Obi')
            ->assertSee('value="18.00"', false)
            ->assertDontSee('Choose a term, class, and subject above');

        // Mobile friendly table (standing rule: "genuinely usable on a
        // phone"): this is the widest data table in the product (one
        // column per assessment component plus total/grade/remark), the
        // clearest case for the .table-stack collapse, each component's
        // own name carries through as the mobile card's data-label.
        $response->assertSee('class="scores-table table-stack"', false)
            ->assertSee('data-label="'.$first->name.'"', false)
            ->assertSee('data-label="Total"', false)
            ->assertSee('data-label="Remark"', false);
    }

    public function test_a_teacher_with_no_teaching_assignments_sees_a_helpful_empty_state(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examseven');
        $this->seedAcademics($school);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations")
            ->assertOk()
            ->assertSee('not assigned to teach');
    }

    /**
     * Bulk score entry (user request 2026-07-12: "the teacher should be
     * able to download the template that has the names, subjects... fill
     * it and upload it back and it saves automatically"). Template is
     * pre-filled with any existing scores, columns are "Subject: Component".
     */
    public function test_the_bulk_template_download_is_prefilled_with_existing_scores(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'exambulkone');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$first->id => 18, $second->id => 16, $exam->id => 52]],
        ]);

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations/scores/bulk-template?term_id={$f['term']->id}&class_arm_id={$f['classArm']->id}&subject_id={$f['subject']->id}");

        $response->assertOk();
        $csv = $response->getContent();
        $this->assertStringContainsString('admission_number,name,"Mathematics: First CA","Mathematics: Second CA","Mathematics: Exam"', $csv);
        $this->assertStringContainsString('A1,"Ada Obi",18,16,52', $csv);
        $this->assertStringContainsString('A2,"Bode Ade",,,', $csv);
    }

    public function test_uploading_a_filled_bulk_template_saves_scores_and_skips_blank_cells(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'exambulktwo');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);

        $csv = "admission_number,name,\"Mathematics: First CA\",\"Mathematics: Second CA\",\"Mathematics: Exam\"\n"
            ."A1,Ada Obi,18,16,52\n"
            .'A2,Bode Ade,,,'; // Bode's row is left entirely blank, must not be touched.
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('scores.csv', $csv);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores/bulk-upload", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'file' => $file,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(3, Score::count());
        $this->assertSame(0, Score::where('student_id', $f['bode']->id)->count());
        $adaTotal = Score::where('student_id', $f['ada']->id)->sum('score');
        app(TenantManager::class)->forget();
        $this->assertEquals(86, $adaTotal);
    }

    public function test_bulk_upload_rejects_an_out_of_range_score_but_still_saves_the_valid_ones(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'exambulkthree');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);

        $csv = "admission_number,name,\"Mathematics: First CA\",\"Mathematics: Second CA\",\"Mathematics: Exam\"\n"
            ."A1,Ada Obi,999,16,52\n"; // First CA is out of range (max 20), the other two are valid.
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('scores.csv', $csv);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores/bulk-upload", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'file' => $file,
        ])->assertRedirect()->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $this->assertSame(2, Score::count());
        $this->assertSame(0, Score::where('assessment_component_id', $f['components'][0]->id)->count());
        app(TenantManager::class)->forget();
    }

    /** A multi-subject teacher's "all my subjects" template covers every subject in one file, and a teacher may still bulk upload just one subject. */
    public function test_a_multi_subject_teachers_bulk_template_covers_every_subject_when_none_is_specified(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'exambulkfour');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);

        app(TenantManager::class)->use($school);
        $english = Subject::create(['name' => 'English']);
        $f['classArm']->schoolClass->subjects()->attach($english->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $staff = Staff::where('user_id', $teacher->id)->first();
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $english->id, 'class_arm_id' => $f['classArm']->id]);
        app(TenantManager::class)->forget();

        $allSubjects = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations/scores/bulk-template?term_id={$f['term']->id}&class_arm_id={$f['classArm']->id}");
        $allSubjects->assertOk();
        $csv = $allSubjects->getContent();
        $this->assertStringContainsString('Mathematics: First CA', $csv);
        $this->assertStringContainsString('English: First CA', $csv);

        $oneSubject = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations/scores/bulk-template?term_id={$f['term']->id}&class_arm_id={$f['classArm']->id}&subject_id={$f['subject']->id}");
        $oneCsv = $oneSubject->getContent();
        $this->assertStringContainsString('Mathematics: First CA', $oneCsv);
        $this->assertStringNotContainsString('English', $oneCsv);
    }

    public function test_bulk_template_is_forbidden_for_a_class_the_teacher_does_not_teach(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'exambulkfive');
        $f = $this->seedAcademics($school);
        // Deliberately no attachTeacher() call.

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations/scores/bulk-template?term_id={$f['term']->id}&class_arm_id={$f['classArm']->id}")
            ->assertForbidden();
    }

    /**
     * A report card past approved (approved/locked/released/archived,
     * ReportCardReview::LOCKED_FOR_EDITING) blocks further score edits for
     * that student's term, a reviewer must send it back to needs_correction
     * first (user directive 2026-07-12, "Intelligent Assessment & Report
     * Card Workflow.docx"). Bode has no review at all, so his score still
     * saves in the same request, only Ada's is skipped.
     */
    public function test_saving_scores_for_a_locked_report_card_is_skipped(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'examlockone');
        $f = $this->seedAcademics($school);
        [$first, $second, $exam] = $f['components'];

        app(TenantManager::class)->use($school);
        ReportCardReview::create([
            'student_id' => $f['ada']->id,
            'term_id' => $f['term']->id,
            'status' => ReportCardReview::STATUS_LOCKED,
            'locked_at' => now(),
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [
                $f['ada']->id => [$first->id => 18, $second->id => 16, $exam->id => 52],
                $f['bode']->id => [$first->id => 10, $second->id => 10, $exam->id => 30],
            ],
        ])->assertRedirect()->assertSessionHas('status', fn ($message) => str_contains($message, 'skipped because their report card is locked'));

        app(TenantManager::class)->use($school);
        $this->assertSame(0, Score::where('student_id', $f['ada']->id)->count());
        $this->assertSame(3, Score::where('student_id', $f['bode']->id)->count());
        app(TenantManager::class)->forget();
    }

    /**
     * Student Performance Dashboard (user directive 2026-07-16, "ALL
     * THERE": build out the remaining Reports & Analytics "Soon" items).
     */
    public function test_admin_sees_term_average_by_class_and_full_at_risk_list(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'perfone');
        $f = $this->seedAcademics($school);
        [$first, $second, $exam] = $f['components'];

        app(TenantManager::class)->use($school);
        // Ada scores well (86%), Bode scores poorly (30/100, below the
        // default 40% at risk threshold).
        Score::create(['student_id' => $f['ada']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $first->id, 'score' => 18]);
        Score::create(['student_id' => $f['ada']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $second->id, 'score' => 16]);
        Score::create(['student_id' => $f['ada']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $exam->id, 'score' => 52]);
        Score::create(['student_id' => $f['bode']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $first->id, 'score' => 5]);
        Score::create(['student_id' => $f['bode']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $second->id, 'score' => 5]);
        Score::create(['student_id' => $f['bode']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $exam->id, 'score' => 20]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/performance-dashboard");
        $response->assertOk()
            ->assertSee('58%') // school average: (86 + 30) / 2 = 58
            ->assertSee('JSS 1 A') // by-class comparison
            ->assertSee('Bode Ade') // flagged, 30% is below the 40% default threshold
            ->assertDontSee('Ada Obi'); // Ada's 86% average must not be flagged as at risk
    }

    /** User request 2026-07-22: "Add pagination to 'Performance at risk' if data there is more than 10. So we should have 10 per page." */
    public function test_the_performance_at_risk_list_paginates_at_ten_per_page_when_there_are_more_than_ten(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'perfpaginate');
        $f = $this->seedAcademics($school);
        [$first, $second, $exam] = $f['components'];

        app(TenantManager::class)->use($school);
        // 12 more at-risk students (30% each, below the 40% default threshold), beyond Ada and Bode already seeded.
        for ($i = 1; $i <= 12; $i++) {
            $student = Student::create(['admission_number' => "P{$i}", 'first_name' => "Risk{$i}", 'last_name' => 'Student', 'current_class_arm_id' => $f['classArm']->id]);
            Score::create(['student_id' => $student->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $first->id, 'score' => 5]);
            Score::create(['student_id' => $student->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $second->id, 'score' => 5]);
            Score::create(['student_id' => $student->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $exam->id, 'score' => 20]);
        }
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/performance-dashboard");

        // The pager controls are server rendered (only the page-number text is filled in client side by JS,
        // untestable here without a JS runner); presence of the controls confirms pagination kicked in for
        // more than 10 items, and every item still exists in the markup for the client-side pager to page through.
        $response->assertOk()
            ->assertSee('Risk1 Student')
            ->assertSee('Risk12 Student')
            ->assertSee('Previous')
            ->assertSee('Next');
    }

    /** The pager controls must not appear at all when there are 10 or fewer at-risk students. */
    public function test_the_performance_at_risk_list_has_no_pager_when_ten_or_fewer(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'perfnopaginate');
        $f = $this->seedAcademics($school);
        [$first, $second, $exam] = $f['components'];

        app(TenantManager::class)->use($school);
        Score::create(['student_id' => $f['bode']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $first->id, 'score' => 5]);
        Score::create(['student_id' => $f['bode']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $second->id, 'score' => 5]);
        Score::create(['student_id' => $f['bode']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $exam->id, 'score' => 20]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/performance-dashboard");

        $response->assertOk()->assertSee('Bode Ade')->assertDontSee('Previous');
    }

    public function test_a_subject_teacher_only_sees_their_own_scoped_class_average(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'perftwo');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);
        [$first, $second, $exam] = $f['components'];

        app(TenantManager::class)->use($school);
        Score::create(['student_id' => $f['ada']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $first->id, 'score' => 20]);
        Score::create(['student_id' => $f['ada']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $second->id, 'score' => 20]);
        Score::create(['student_id' => $f['ada']->id, 'subject_id' => $f['subject']->id, 'class_arm_id' => $f['classArm']->id, 'term_id' => $f['term']->id, 'assessment_component_id' => $exam->id, 'score' => 60]);
        app(TenantManager::class)->forget();

        // A scoped teacher never sees the school wide "by class" comparison list.
        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations/performance-dashboard")
            ->assertOk()
            ->assertSee('100%')
            ->assertDontSee('By class');
    }

    public function test_a_role_without_examinations_view_cannot_reach_the_performance_dashboard(): void
    {
        [$school, $user] = $this->schoolWithMember('security', 'perfthree');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/examinations/performance-dashboard")->assertForbidden();
    }
}
