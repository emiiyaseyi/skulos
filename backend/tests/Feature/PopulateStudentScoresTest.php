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
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentTermRemark;
use App\Models\Tenant\Subject;
use App\Models\Tenant\SubjectRemark;
use App\Models\Tenant\Term;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Populating every real student with live scores and comments (user
 * request 2026-07-12: "populate each student with live scores, comments
 * etc (leave some for us to review the workflow, approval etc)"). Must
 * never touch ReportCardReview, only Score/SubjectRemark/
 * StudentTermRemark, and must never overwrite anything already entered.
 */
class PopulateStudentScoresTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    /** Builds one class arm, one subject, three components, a current term, and one student. */
    protected function seedSchool(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 2]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);

        AssessmentComponent::create(['name' => 'First CA', 'type' => 'ca', 'max_score' => 20, 'sequence' => 1]);
        AssessmentComponent::create(['name' => 'Second CA', 'type' => 'ca', 'max_score' => 20, 'sequence' => 2]);
        AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 60, 'sequence' => 3]);

        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();

        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);

        app(TenantManager::class)->forget();

        return [$school, $classArm, $subject, $term, $student];
    }

    public function test_it_populates_scores_within_each_components_max_and_a_subject_remark(): void
    {
        [$school, , $subject, $term, $student] = $this->seedSchool('popscoreone');

        $this->artisan('schools:populate-scores', ['school' => 'popscoreone'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $scores = Score::where('student_id', $student->id)->where('subject_id', $subject->id)->where('term_id', $term->id)->get();
        $remark = SubjectRemark::where('student_id', $student->id)->where('subject_id', $subject->id)->where('term_id', $term->id)->first();
        $termRemark = StudentTermRemark::where('student_id', $student->id)->where('term_id', $term->id)->first();
        app(TenantManager::class)->forget();

        $this->assertCount(3, $scores, 'one score per assessment component');
        foreach ($scores as $score) {
            $this->assertGreaterThanOrEqual(0, (float) $score->score);
        }
        $this->assertNotNull($remark, 'a subject remark should be suggested from the comment bank');
        $this->assertNotEmpty($remark->remark);
        $this->assertNotNull($termRemark);
        $this->assertNotEmpty($termRemark->class_teacher_remark);
    }

    public function test_it_never_overwrites_a_subject_that_already_has_a_real_score(): void
    {
        [$school, $classArm, $subject, $term, $student] = $this->seedSchool('popscoretwo');

        app(TenantManager::class)->use($school);
        $component = AssessmentComponent::orderBy('sequence')->first();
        Score::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_arm_id' => $classArm->id,
            'term_id' => $term->id,
            'assessment_component_id' => $component->id,
            'score' => 17,
        ]);
        app(TenantManager::class)->forget();

        $this->artisan('schools:populate-scores', ['school' => 'popscoretwo'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $scores = Score::where('student_id', $student->id)->where('subject_id', $subject->id)->where('term_id', $term->id)->get();
        app(TenantManager::class)->forget();

        $this->assertCount(1, $scores, 'a subject with any real score must not be topped up with generated ones');
        $this->assertEquals(17, (float) $scores->first()->score);
    }

    public function test_running_it_twice_does_not_duplicate_scores(): void
    {
        [$school, , $subject, $term, $student] = $this->seedSchool('popscorethree');

        $this->artisan('schools:populate-scores', ['school' => 'popscorethree'])->assertSuccessful();
        $this->artisan('schools:populate-scores', ['school' => 'popscorethree'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $count = Score::where('student_id', $student->id)->where('subject_id', $subject->id)->where('term_id', $term->id)->count();
        app(TenantManager::class)->forget();

        $this->assertSame(3, $count);
    }

    /** The explicit instruction: leave the review/approval workflow untouched. */
    public function test_it_never_creates_or_changes_a_report_card_review(): void
    {
        [$school, , , $term, $student] = $this->seedSchool('popscorefour');

        app(TenantManager::class)->use($school);
        $reviewCountBefore = ReportCardReview::count();
        app(TenantManager::class)->forget();

        $this->artisan('schools:populate-scores', ['school' => 'popscorefour'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $reviewCountAfter = ReportCardReview::count();
        app(TenantManager::class)->forget();

        $this->assertSame(0, $reviewCountBefore);
        $this->assertSame(0, $reviewCountAfter, 'this command must never create a ReportCardReview row');
    }

    public function test_it_skips_a_school_with_no_terms_set_up(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'popscorefive']));
        app(TenantManager::class)->use($school);
        AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);
        app(TenantManager::class)->forget();

        $this->artisan('schools:populate-scores', ['school' => 'popscorefive'])
            ->expectsOutputToContain('no terms set up')
            ->assertSuccessful();
    }

    /**
     * The extended instruction (2026-07-13): "the real scores should be
     * for all the terms as i want two sessions per school." A school
     * with only one session gets a second, historical one added
     * automatically, and every term across both sessions gets scores.
     */
    public function test_it_adds_a_second_session_and_populates_every_term_across_both(): void
    {
        [$school, , $subject, $originalTerm, $student] = $this->seedSchool('popscoreseven');

        $this->artisan('schools:populate-scores', ['school' => 'popscoreseven'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $sessions = AcademicSession::orderBy('id')->get();
        $allTerms = Term::all();
        $scoresPerTerm = Score::where('student_id', $student->id)
            ->where('subject_id', $subject->id)
            ->get()
            ->groupBy('term_id');
        app(TenantManager::class)->forget();

        $this->assertCount(2, $sessions, 'a school with only one session should end up with two');
        $this->assertSame('2024/2025 Session', $sessions->last()->name, 'the added session should be the year immediately before the existing one');
        $this->assertGreaterThanOrEqual(4, $allTerms->count(), 'the original term plus the new session\'s three terms');
        $this->assertCount($allTerms->count(), $scoresPerTerm, 'every term, including the newly added session\'s, should have scores for this subject');
    }

    public function test_it_skips_a_school_with_no_assessment_components(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'popscoresix']));
        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1])->makeCurrent();
        app(TenantManager::class)->forget();

        $this->artisan('schools:populate-scores', ['school' => 'popscoresix'])
            ->expectsOutputToContain('no assessment components')
            ->assertSuccessful();
    }
}
