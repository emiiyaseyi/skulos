<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\ReportCards\ReportCardPreferences;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Narrative Evaluation (user request 2026-07-18): "allows the admin...
 * to set a class or some classes to have the Narrative Evaluation style
 * of report where scores are not entered into the student report card
 * but more of Narrative Evaluation (comment based)". Per class, every
 * plan, admin only. Builds on the pre-existing school wide "narrative"
 * report card template and StudentSubjectComment entry, making that
 * choice selectable per class instead of one all-or-nothing setting.
 */
class NarrativeEvaluationTest extends TestCase
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

    /**
     * @return array{crecheClass: SchoolClass, crecheArm: ClassArm, jssClass: SchoolClass, jssArm: ClassArm, term: Term, crecheStudent: Student, jssStudent: Student, subject: Subject}
     */
    protected function seedTwoClasses(School $school): array
    {
        app(TenantManager::class)->use($school);

        $nursery = Section::create(['name' => 'Nursery', 'sequence' => 1]);
        $secondary = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $crecheClass = SchoolClass::create(['section_id' => $nursery->id, 'name' => 'Creche/Reception', 'sequence' => 1]);
        $jssClass = SchoolClass::create(['section_id' => $secondary->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $subject = Subject::create(['name' => 'Numeracy']);
        $crecheClass->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $jssClass->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $arm = Arm::create(['name' => 'A']);
        $crecheArm = ClassArm::create(['school_class_id' => $crecheClass->id, 'arm_id' => $arm->id]);
        $jssArm = ClassArm::create(['school_class_id' => $jssClass->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();

        $crecheStudent = Student::create(['admission_number' => 'C1', 'first_name' => 'Timi', 'last_name' => 'Bello', 'current_class_arm_id' => $crecheArm->id]);
        $jssStudent = Student::create(['admission_number' => 'J1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $jssArm->id]);

        app(TenantManager::class)->forget();

        return compact('crecheClass', 'crecheArm', 'jssClass', 'jssArm', 'term', 'crecheStudent', 'jssStudent', 'subject');
    }

    public function test_an_admin_can_set_a_class_to_narrative_evaluation(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'narrativeone');
        ['crecheClass' => $crecheClass] = $this->seedTwoClasses($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/narrative-evaluation", [
            'narrative_class_ids' => [$crecheClass->id],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertTrue($crecheClass->fresh()->isNarrativeEvaluation());
        app(TenantManager::class)->forget();
    }

    public function test_saving_declaratively_reverts_an_unchecked_class_back_to_scores(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'narrativetwo');
        ['crecheClass' => $crecheClass] = $this->seedTwoClasses($school);

        app(TenantManager::class)->use($school);
        $crecheClass->update(['evaluation_style' => SchoolClass::EVALUATION_STYLE_NARRATIVE]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/narrative-evaluation", [
            'narrative_class_ids' => [],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertFalse($crecheClass->fresh()->isNarrativeEvaluation());
        app(TenantManager::class)->forget();
    }

    public function test_a_plain_teacher_cannot_reach_the_narrative_evaluation_settings(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('teacher', 'narrativethree');

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/examinations/narrative-evaluation")
            ->assertForbidden();
    }

    public function test_a_narrative_class_is_excluded_from_the_score_entry_picker(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'narrativefour');
        ['crecheClass' => $crecheClass, 'jssClass' => $jssClass] = $this->seedTwoClasses($school);

        app(TenantManager::class)->use($school);
        $crecheClass->update(['evaluation_style' => SchoolClass::EVALUATION_STYLE_NARRATIVE]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations");

        $response->assertOk()->assertSee('JSS 1');
        // It has no scores to enter, so it must not appear as a choice in
        // the class/subject <select>, but per the 2026-07-21 fix below it
        // still needs to be visible SOMEWHERE on this page (as a link out
        // to its own comment-entry screen), so this checks the actual
        // <option> list, not the whole page.
        preg_match('/<select id="pair".*?<\/select>/s', $response->getContent(), $picker);
        $this->assertStringNotContainsString('Creche/Reception', $picker[0] ?? '');
    }

    /** User report 2026-07-21: "this kind of class is not seen under the enter score page so one can enter the remarks for each subject under the Narrative Evaluation option." */
    public function test_a_narrative_class_is_surfaced_on_the_score_entry_page_with_a_link_to_its_comment_entry_screen(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'narrativenine');
        ['crecheClass' => $crecheClass] = $this->seedTwoClasses($school);

        app(TenantManager::class)->use($school);
        $crecheClass->update(['evaluation_style' => SchoolClass::EVALUATION_STYLE_NARRATIVE]);
        $crecheClassId = $crecheClass->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations")
            ->assertOk()
            ->assertSee('Narrative Evaluation classes')
            ->assertSee('Creche/Reception')
            ->assertSee(route('school.report-cards.class.entry', ['school' => $school->slug, 'classId' => $crecheClassId]), false);
    }

    public function test_a_teacher_without_report_cards_update_does_not_see_the_narrative_classes_section(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'narrativeten');
        ['crecheClass' => $crecheClass] = $this->seedTwoClasses($school);

        app(TenantManager::class)->use($school);
        $crecheClass->update(['evaluation_style' => SchoolClass::EVALUATION_STYLE_NARRATIVE]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations")
            ->assertOk()
            ->assertDontSee('Narrative Evaluation classes');
    }

    public function test_the_class_entry_screen_shows_comments_for_a_narrative_class_even_when_the_schools_global_template_is_not_narrative(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'narrativefive');
        ['crecheClass' => $crecheClass, 'crecheArm' => $crecheArm, 'term' => $term, 'subject' => $subject] = $this->seedTwoClasses($school);

        app(TenantManager::class)->use($school);
        $crecheClass->update(['evaluation_style' => SchoolClass::EVALUATION_STYLE_NARRATIVE]);
        $staff = Staff::create(['staff_id' => 'T1', 'first_name' => 'Tade', 'last_name' => 'Teacher']);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subject->id, 'class_arm_id' => $crecheArm->id]);
        app(ReportCardPreferences::class)->setTemplate('classic');
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$crecheClass->id}/entry?term_id={$term->id}")
            ->assertOk()
            ->assertSee('Numeracy');
    }

    public function test_a_narrative_class_students_report_card_renders_narrative_even_when_the_schools_global_template_is_not(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'narrativesix');
        ['crecheClass' => $crecheClass, 'crecheStudent' => $student, 'term' => $term] = $this->seedTwoClasses($school);

        app(TenantManager::class)->use($school);
        $crecheClass->update(['evaluation_style' => SchoolClass::EVALUATION_STYLE_NARRATIVE]);
        app(ReportCardPreferences::class)->setTemplate('classic');
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertSee('rc-eval', false);
    }

    public function test_the_nav_shows_narrative_evaluation_under_examinations_for_an_admin(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'narrativeseven');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Narrative Evaluation');
    }
}
