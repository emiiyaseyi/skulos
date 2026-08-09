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
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\ReportCards\ReportCardService;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Mid-term report cards (user request 2026-08-07): a school can build a
 * lighter mid-term report from only part of a term's assessment
 * components (by default CA1+CA2), admin-configured on Assessment
 * Structure, going through the exact same ReportCardReview workflow as
 * the existing full-term report, independently, for the same
 * student/term.
 */
class MidTermReportCardTest extends TestCase
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

        $ca1 = AssessmentComponent::create(['name' => 'CA 1', 'max_score' => 10, 'sequence' => 1, 'counts_for_midterm' => true, 'counts_for_fullterm' => true]);
        $ca2 = AssessmentComponent::create(['name' => 'CA 2', 'max_score' => 20, 'sequence' => 2, 'counts_for_midterm' => true, 'counts_for_fullterm' => true]);
        $exam = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 70, 'sequence' => 3, 'counts_for_midterm' => false, 'counts_for_fullterm' => true]);

        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);

        foreach ([$ca1->id => 8, $ca2->id => 16, $exam->id => 50] as $componentId => $score) {
            Score::create([
                'student_id' => $ada->id, 'subject_id' => $subject->id, 'term_id' => $term->id,
                'assessment_component_id' => $componentId, 'class_arm_id' => $classArm->id, 'score' => $score,
            ]);
        }

        app(TenantManager::class)->forget();

        return compact('classArm', 'term', 'subject', 'ca1', 'ca2', 'exam', 'ada');
    }

    public function test_for_report_type_filters_components_to_the_right_flag(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'mtcomp');
        $f = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $midTerm = AssessmentComponent::forReportType($f['term'], ReportCardReview::REPORT_TYPE_MID_TERM);
        $fullTerm = AssessmentComponent::forReportType($f['term'], ReportCardReview::REPORT_TYPE_FULL_TERM);

        $this->assertSame(['CA 1', 'CA 2'], $midTerm->pluck('name')->all());
        $this->assertSame(['CA 1', 'CA 2', 'Exam'], $fullTerm->pluck('name')->all());
        $this->assertSame(30.0, AssessmentComponent::totalPossibleForReportType($f['term'], ReportCardReview::REPORT_TYPE_MID_TERM));
        $this->assertSame(100.0, AssessmentComponent::totalPossibleForReportType($f['term'], ReportCardReview::REPORT_TYPE_FULL_TERM));
        app(TenantManager::class)->forget();
    }

    public function test_report_card_service_returns_the_right_component_columns_and_normalized_grade_per_report_type(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'mtcols');
        $f = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $cards = app(ReportCardService::class);

        $midData = $cards->forStudent($f['ada'], $f['term'], null, ReportCardReview::REPORT_TYPE_MID_TERM);
        $this->assertSame(['CA 1', 'CA 2'], $midData['component_columns']);
        $this->assertSame('mid_term', $midData['report_type']);
        // CA1 8 + CA2 16 = 24 raw, out of every subject's own component set.
        $this->assertSame(24.0, $midData['subjects'][0]['total']);

        $fullData = $cards->forStudent($f['ada'], $f['term'], null, ReportCardReview::REPORT_TYPE_FULL_TERM);
        $this->assertSame(['CA 1', 'CA 2', 'Exam'], $fullData['component_columns']);
        $this->assertSame('full_term', $fullData['report_type']);
        $this->assertSame(74.0, $fullData['subjects'][0]['total']);
        app(TenantManager::class)->forget();
    }

    public function test_a_mid_term_and_full_term_review_are_independent_rows_for_the_same_student_and_term(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'mtworkflow');
        $f = $this->seedAcademics($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$f['ada']->id}/generate", [
            'term_id' => $f['term']->id, 'report_type' => ReportCardReview::REPORT_TYPE_MID_TERM,
        ])->assertRedirect();
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$f['ada']->id}/generate", [
            'term_id' => $f['term']->id, 'report_type' => ReportCardReview::REPORT_TYPE_FULL_TERM,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(2, ReportCardReview::where('student_id', $f['ada']->id)->where('term_id', $f['term']->id)->count());
        $midReview = ReportCardReview::where('student_id', $f['ada']->id)->where('term_id', $f['term']->id)->where('report_type', 'mid_term')->first();
        $fullReview = ReportCardReview::where('student_id', $f['ada']->id)->where('term_id', $f['term']->id)->where('report_type', 'full_term')->first();
        $this->assertNotNull($midReview);
        $this->assertNotNull($fullReview);
        $this->assertNotSame($midReview->id, $fullReview->id);
        app(TenantManager::class)->forget();

        // Releasing only the mid-term one must not touch the full-term row.
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$f['ada']->id}/release", [
            'term_id' => $f['term']->id, 'report_type' => ReportCardReview::REPORT_TYPE_MID_TERM,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(ReportCardReview::STATUS_RELEASED, $midReview->fresh()->status);
        $this->assertNotSame(ReportCardReview::STATUS_RELEASED, $fullReview->fresh()->status);
        app(TenantManager::class)->forget();
    }

    public function test_a_released_mid_term_report_is_viewable_by_the_family_while_the_full_term_one_stays_hidden(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'mtfamily');
        $f = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $user = User::factory()->create();
        $f['ada']->update(['user_id' => $user->id]);
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($user->id, ['role_id' => $studentRole->id, 'status' => 'active']);
        ReportCardReview::create(['student_id' => $f['ada']->id, 'term_id' => $f['term']->id, 'report_type' => 'mid_term', 'status' => ReportCardReview::STATUS_RELEASED]);
        ReportCardReview::create(['student_id' => $f['ada']->id, 'term_id' => $f['term']->id, 'report_type' => 'full_term', 'status' => ReportCardReview::STATUS_DRAFT]);
        app(TenantManager::class)->forget();

        $this->actingAs($user)->get("http://{$school->slug}.localhost/report-cards/{$f['ada']->id}/{$f['term']->id}?report_type=mid_term")
            ->assertOk();
        $this->actingAs($user)->get("http://{$school->slug}.localhost/report-cards/{$f['ada']->id}/{$f['term']->id}?report_type=full_term")
            ->assertNotFound();
    }
}
