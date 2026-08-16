<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The school-wide subject screens (2026-08-16). Subjects could already
 * be managed from inside one class at a time; what was missing was the
 * view across the whole school, and a way to cover a subject with
 * several teachers until no class is left without one.
 */
class SubjectManagementTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config([
            'tenancy.sqlite_path' => $this->tenantDir,
            'tenancy.driver' => 'sqlite',
            'app.domain' => 'localhost',
        ]);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $owner = User::factory()->create();
        $role = app(Rbac::class)->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $owner];
    }

    /** Two arms both offering one subject, plus two teachers. */
    protected function seedAcademics(School $school): array
    {
        return app(TenantManager::class)->connectTo($school, function () {
            $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
            $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
            $armA = ClassArm::create(['school_class_id' => $class->id, 'name' => 'A']);
            $armB = ClassArm::create(['school_class_id' => $class->id, 'name' => 'B']);

            $subject = Subject::create(['name' => 'Mathematics', 'sequence' => 1]);
            foreach ([$armA, $armB] as $arm) {
                DB::connection('tenant')->table('class_arm_subjects')->insert(['class_arm_id' => $arm->id, 'subject_id' => $subject->id]);
            }

            $one = Staff::create(['first_name' => 'Ada', 'last_name' => 'Teacher', 'staff_id' => 'ST/001', 'gender' => 'Female']);
            $two = Staff::create(['first_name' => 'Bola', 'last_name' => 'Teacher', 'staff_id' => 'ST/002', 'gender' => 'Male']);

            return compact('subject', 'armA', 'armB', 'one', 'two');
        });
    }

    public function test_the_list_shows_how_many_classes_still_have_no_teacher(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subjone');
        $seed = $this->seedAcademics($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/subjects")
            ->assertOk()
            ->assertSee('Mathematics')
            // Both arms offer it and neither has a teacher yet.
            ->assertSee('2 class(es)');
    }

    public function test_a_subject_can_be_covered_by_several_teachers_until_none_are_left(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subjtwo');
        $seed = $this->seedAcademics($school);
        $host = "http://{$school->slug}.localhost";

        // First teacher takes one arm; the other must still be reported
        // as uncovered, which is the whole point of the screen.
        $this->actingAs($owner)->post("{$host}/subjects/{$seed['subject']->id}/teacher", [
            'staff_id' => $seed['one']->id,
            'class_arm_ids' => [$seed['armA']->id],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(1, TeachingAssignment::where('subject_id', $seed['subject']->id)->count());
        app(TenantManager::class)->forget();

        // Second teacher covers the remainder.
        $this->actingAs($owner)->post("{$host}/subjects/{$seed['subject']->id}/teacher", [
            'staff_id' => $seed['two']->id,
            'class_arm_ids' => [$seed['armB']->id],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $assignments = TeachingAssignment::where('subject_id', $seed['subject']->id)->get();
        $this->assertCount(2, $assignments);
        $this->assertEqualsCanonicalizing(
            [$seed['one']->id, $seed['two']->id],
            $assignments->pluck('staff_id')->all()
        );
        app(TenantManager::class)->forget();
    }

    public function test_reassigning_a_class_moves_it_rather_than_leaving_two_teachers_on_it(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subjthree');
        $seed = $this->seedAcademics($school);
        $host = "http://{$school->slug}.localhost";

        $this->actingAs($owner)->post("{$host}/subjects/{$seed['subject']->id}/teacher", [
            'staff_id' => $seed['one']->id, 'class_arm_ids' => [$seed['armA']->id],
        ]);
        $this->actingAs($owner)->post("{$host}/subjects/{$seed['subject']->id}/teacher", [
            'staff_id' => $seed['two']->id, 'class_arm_ids' => [$seed['armA']->id],
        ]);

        app(TenantManager::class)->use($school);
        $rows = TeachingAssignment::where('subject_id', $seed['subject']->id)
            ->where('class_arm_id', $seed['armA']->id)->get();
        // One row, now belonging to the second teacher: two teachers
        // silently owning the same class is the bug this guards against.
        $this->assertCount(1, $rows);
        $this->assertSame($seed['two']->id, $rows->first()->staff_id);
        app(TenantManager::class)->forget();
    }

    public function test_the_workbook_offers_the_schools_own_staff_as_a_dropdown(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subjfour');
        $this->seedAcademics($school);

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/subjects/workbook");
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        file_put_contents($path, $response->getContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

        $this->assertNotNull($book->getSheetByName('Staff'), 'No staff sheet for the dropdown to read.');
        $names = $book->getSheetByName('Staff')->toArray();
        $this->assertSame('Ada Teacher', $names[0][0]);

        @unlink($path);
    }

    public function test_the_workbook_is_refused_when_the_school_has_no_staff_yet(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subjfive');

        // Handing over a workbook whose only meaningful column has
        // nothing to choose from would just waste the admin's time.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/subjects/workbook")
            ->assertStatus(422);
    }
}
