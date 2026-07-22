<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\ExamPaper;
use App\Models\Tenant\ExamTimetableEntry;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Subject;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Examination Timetable (user request 2026-07-21): a duration-driven,
 * dated exam-day structure, distinct from the recurring weekly grid
 * Timetable Management already covers.
 */
class ExaminationTimetableTest extends TestCase
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

    public function test_admin_can_define_the_exam_day_structure_with_auto_computed_times(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'examone');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/timetable/exam/papers", [
            'first_start' => '08:30',
            'papers' => [
                ['name' => 'Paper 1', 'duration_minutes' => 60, 'is_break' => '0'],
                ['name' => 'Break', 'duration_minutes' => 20, 'is_break' => '1'],
                ['name' => 'Paper 2', 'duration_minutes' => 90, 'is_break' => '0'],
            ],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $papers = ExamPaper::orderBy('sequence')->get();
        app(TenantManager::class)->forget();

        $this->assertSame(3, $papers->count());
        $this->assertSame('08:30', substr($papers[0]->starts_at, 0, 5));
        $this->assertSame('09:30', substr($papers[0]->ends_at, 0, 5));
        $this->assertSame('09:30', substr($papers[1]->starts_at, 0, 5)); // Break starts right after Paper 1
        $this->assertSame('09:50', substr($papers[1]->ends_at, 0, 5));
        $this->assertSame('09:50', substr($papers[2]->starts_at, 0, 5)); // Paper 2 starts right after the break
        $this->assertSame('11:20', substr($papers[2]->ends_at, 0, 5));
        $this->assertTrue((bool) $papers[1]->is_break);
        $this->assertFalse((bool) $papers[2]->is_break);
    }

    protected function seedPapersAndSection(School $school): array
    {
        app(TenantManager::class)->use($school);
        ExamPaper::create(['name' => 'Paper 1', 'sequence' => 1, 'duration_minutes' => 60, 'is_break' => false, 'starts_at' => '08:00', 'ends_at' => '09:00']);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $sectionId = $section->id;
        $classId = $class->id;
        $subjectId = $subject->id;
        $paperId = ExamPaper::first()->id;
        app(TenantManager::class)->forget();

        return compact('sectionId', 'classId', 'subjectId', 'paperId');
    }

    public function test_admin_can_assign_a_subject_to_a_section_for_one_exam_date(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'examtwo');
        ['sectionId' => $sectionId, 'subjectId' => $subjectId, 'paperId' => $paperId] = $this->seedPapersAndSection($school);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/timetable/exam", [
            'scope_type' => 'section',
            'scope_id' => $sectionId,
            'rows' => ['2026-11-25' => [$paperId => $subjectId]],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $entry = ExamTimetableEntry::where('scope_type', 'section')->where('scope_id', $sectionId)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($entry);
        $this->assertSame($subjectId, $entry->subject_id);
        $this->assertSame('2026-11-25', $entry->exam_date->toDateString());
    }

    public function test_the_index_shows_the_grid_once_a_scope_and_date_range_are_chosen(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'examthree');
        ['sectionId' => $sectionId] = $this->seedPapersAndSection($school);

        $response = $this->actingAs($admin)->get("http://{$school->slug}.localhost/timetable/exam?scope_type=section&scope_id={$sectionId}&start_date=2026-11-25&days=3");

        $response->assertOk()->assertSee('Paper 1')->assertSee('Mathematics');
    }

    /** User report 2026-07-22: visiting the page with nothing picked showed no data even though it had already been populated. */
    public function test_the_index_defaults_to_whatever_already_has_real_entries(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'examseven');
        ['sectionId' => $sectionId, 'subjectId' => $subjectId, 'paperId' => $paperId] = $this->seedPapersAndSection($school);

        app(TenantManager::class)->use($school);
        ExamTimetableEntry::create(['exam_date' => '2026-11-25', 'exam_paper_id' => $paperId, 'scope_type' => 'section', 'scope_id' => $sectionId, 'subject_id' => $subjectId]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($admin)->get("http://{$school->slug}.localhost/timetable/exam");

        $response->assertOk()->assertSee('Paper 1')->assertSee('Mathematics');
    }

    public function test_downloading_the_blank_template_lists_every_date_and_paper(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'examfour');
        ['sectionId' => $sectionId] = $this->seedPapersAndSection($school);

        $response = $this->actingAs($admin)->get("http://{$school->slug}.localhost/timetable/exam/template?scope_type=section&scope_id={$sectionId}&start_date=2026-11-25&days=2");

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('2026-11-25', $content);
        $this->assertStringContainsString('2026-11-26', $content);
        $this->assertStringContainsString('Paper 1', $content);
    }

    public function test_uploading_a_filled_template_saves_entries(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'examfive');
        ['sectionId' => $sectionId, 'subjectId' => $subjectId] = $this->seedPapersAndSection($school);

        $csv = "Date,Paper,Time,Subject\n2026-11-25,Paper 1,08:00 to 09:00,Mathematics\n";
        $file = UploadedFile::fake()->createWithContent('filled.csv', $csv);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/timetable/exam/upload", [
            'scope_type' => 'section',
            'scope_id' => $sectionId,
            'file' => $file,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $entry = ExamTimetableEntry::where('scope_type', 'section')->where('scope_id', $sectionId)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($entry);
        $this->assertSame($subjectId, $entry->subject_id);
    }

    public function test_a_scoped_teacher_cannot_manage_the_examination_timetable(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'examsix');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/timetable/exam")->assertForbidden();
        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/timetable/exam/papers")->assertForbidden();
    }
}
