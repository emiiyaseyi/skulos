<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\House;
use App\Models\Tenant\HouseSponsor;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Houses (user request 2026-07-10): the House model existed with no admin
 * screen at all. Default catalog, manual per student assignment, and a
 * CSV round trip (download every student pre-filled, fill in the house
 * column, re-upload).
 */
class HouseScreenTest extends TestCase
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

    public function test_admin_can_load_default_houses(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'houseone');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/apply-defaults")->assertRedirect();

        app(TenantManager::class)->use($school);
        $count = House::count();
        app(TenantManager::class)->forget();
        $this->assertSame(4, $count);
    }

    /**
     * User report 2026-07-26 (reached via the Help Center's own "Setting
     * up school houses" article link): "parents users should not have
     * access to this at all." A parent holds 'students.view' for a
     * completely different, narrower reason (viewing their own child's
     * profile), not for browsing the school's administrative structure.
     */
    public function test_a_parent_cannot_reach_the_houses_screen_despite_holding_students_view(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'housefour');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/houses")->assertForbidden();
    }

    public function test_a_student_cannot_reach_the_houses_screen_despite_holding_students_view(): void
    {
        [$school, $student] = $this->schoolWithMember('student', 'housefive');

        $this->actingAs($student)->get("http://{$school->slug}.localhost/houses")->assertForbidden();
    }

    public function test_loading_defaults_twice_does_not_duplicate(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwo');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/apply-defaults");
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/apply-defaults");

        app(TenantManager::class)->use($school);
        $count = House::count();
        app(TenantManager::class)->forget();
        $this->assertSame(4, $count);
    }

    public function test_admin_can_add_a_house_and_assign_several_students_in_one_save(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housethree');

        app(TenantManager::class)->use($school);
        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $bode = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses", ['name' => 'Red House'])->assertRedirect();

        app(TenantManager::class)->use($school);
        $house = House::where('name', 'Red House')->first();
        app(TenantManager::class)->forget();

        // One request assigns both students, no per-row reload.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/assign", [
            'assignments' => [$ada->id => $house->id, $bode->id => $house->id],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $adaAfter = Student::find($ada->id);
        $bodeAfter = Student::find($bode->id);
        app(TenantManager::class)->forget();
        $this->assertSame($house->id, $adaAfter->house_id);
        $this->assertSame($house->id, $bodeAfter->house_id);
    }

    /**
     * Regression test for a 2026-07-10 bug caught only by a live smoke
     * test, not the suite: Laravel's ConvertEmptyStringsToNull middleware
     * turns an empty "No house" selection into null before it reaches the
     * controller, not the empty string "" the code assumed. (int) null
     * casts to 0, not null, and 0 matches no house row, so saving with
     * any "No house" selection threw a foreign key violation (a 500),
     * not a graceful unassignment.
     */
    public function test_choosing_no_house_in_the_bulk_form_unassigns_rather_than_500s(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housesixteen');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'house_id' => $house->id]);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        // Simulate the real HTTP submission: an empty option value arrives
        // as null, not "", after ConvertEmptyStringsToNull.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/assign", [
            'assignments' => [$studentId => null],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = Student::find($studentId);
        app(TenantManager::class)->forget();
        $this->assertNull($updated->house_id);
    }

    public function test_the_unassigned_only_filter_shows_only_students_without_a_house(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwelve');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'house_id' => $house->id]);
        Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/houses/assign?unassigned=1")
            ->assertOk()->assertSee('Bode Ade')->assertDontSee('Ada Obi');
    }

    public function test_admin_can_rename_a_house(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housethirteen');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $houseId = $house->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/{$houseId}", [
            'name' => 'Crimson House',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $renamed = House::find($houseId);
        app(TenantManager::class)->forget();
        $this->assertSame('Crimson House', $renamed->name);
    }

    public function test_retiring_a_house_hides_it_from_assignment_but_keeps_its_members(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housefourteen');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'house_id' => $house->id]);
        $houseId = $house->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/{$houseId}/toggle-active")->assertRedirect();

        app(TenantManager::class)->use($school);
        $retired = House::find($houseId);
        $studentAfter = Student::find($student->id);
        app(TenantManager::class)->forget();

        $this->assertFalse($retired->is_active);
        $this->assertSame($houseId, $studentAfter->house_id, 'retiring must not unassign existing members');

        // Retired houses drop out of the assignment picker's <option> list.
        // Checking the rendered <select> options directly via a regex, not
        // assertSee/assertDontSee, since the retire action's own flash
        // message legitimately names the house on this very next page load
        // (a plain "don't see the name" would false-fail on that), and the
        // option may or may not carry a "selected" attribute depending on
        // the student's current assignment.
        $pageWithoutOption = $this->actingAs($admin)->get("http://{$school->slug}.localhost/houses/assign")->getContent();
        $this->assertDoesNotMatchRegularExpression('/<option value="'.$houseId.'"[^>]*>Red House<\/option>/', $pageWithoutOption);

        // Reactivating brings it back.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/{$houseId}/toggle-active");
        $pageWithOption = $this->actingAs($admin)->get("http://{$school->slug}.localhost/houses/assign")->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$houseId.'"[^>]*>Red House<\/option>/', $pageWithOption);
    }

    public function test_the_house_page_shows_its_members_and_offers_a_download(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housefifteen');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'house_id' => $house->id]);
        $houseId = $house->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/houses/{$houseId}")
            ->assertOk()->assertSee('Ada Obi')->assertSee('1 member');

        $csv = $this->actingAs($admin)->get("http://{$school->slug}.localhost/houses/{$houseId}/members.csv");
        $csv->assertOk();
        $this->assertStringContainsString('A1', $csv->getContent());
        $this->assertStringContainsString('Ada', $csv->getContent());
    }

    public function test_removing_a_house_unassigns_its_members_without_deleting_them(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housefour');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'house_id' => $house->id]);
        $studentId = $student->id;
        $houseId = $house->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->delete("http://{$school->slug}.localhost/houses/{$houseId}")->assertRedirect();

        app(TenantManager::class)->use($school);
        $student = Student::find($studentId);
        $houseGone = House::find($houseId);
        app(TenantManager::class)->forget();

        $this->assertNotNull($student, 'the student must still exist');
        $this->assertNull($student->house_id);
        $this->assertNull($houseGone);
    }

    public function test_bulk_template_is_prefilled_with_every_active_student(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housefive');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'house_id' => $house->id]);
        Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($admin)->get("http://{$school->slug}.localhost/houses/bulk-template");
        $response->assertOk();
        // Ordered by last name (Ade before Obi); fputcsv quotes any field containing a
        // space, "Red House" included, both standard behavior, not a bug.
        $this->assertSame(
            "admission_number,class,house\nA2,,\nA1,,\"Red House\"\n",
            $response->getContent()
        );
    }

    public function test_bulk_assign_updates_only_rows_with_a_house_filled_in(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housesix');

        app(TenantManager::class)->use($school);
        $red = House::create(['name' => 'Red House']);
        $blue = House::create(['name' => 'Blue House']);
        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'house_id' => $red->id]);
        $bode = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        app(TenantManager::class)->forget();

        // A1 left blank (should stay Red House), A2 assigned to Blue House.
        $csv = "admission_number,class,house\nA1,,\nA2,,Blue House\n";
        $file = UploadedFile::fake()->createWithContent('houses.csv', $csv);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/bulk-assign", ['file' => $file])
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $adaAfter = Student::find($ada->id);
        $bodeAfter = Student::find($bode->id);
        app(TenantManager::class)->forget();

        $this->assertSame($red->id, $adaAfter->house_id, 'a blank house cell must not change an existing assignment');
        $this->assertSame($blue->id, $bodeAfter->house_id);
    }

    public function test_bulk_assign_reports_an_unknown_admission_number_or_house(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'houseseven');

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        app(TenantManager::class)->forget();

        $csv = "admission_number,class,house\nA1,,Nonexistent House\nMISSING,,Red House\n";
        $file = UploadedFile::fake()->createWithContent('houses.csv', $csv);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/bulk-assign", ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * Bulk reactivate/retire/delete with a checkbox (user request
     * 2026-07-11): a mixed selection should only change rows that are
     * not already in the target state, and report how many actually
     * changed rather than how many were ticked.
     */
    public function test_bulk_retire_only_changes_active_houses_in_the_selection(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housenineteen');

        app(TenantManager::class)->use($school);
        $red = House::create(['name' => 'Red House']);
        $blue = House::create(['name' => 'Blue House', 'is_active' => false]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/bulk-action", [
            'house_ids' => [$red->id, $blue->id],
            'action' => 'retire',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $red = House::find($red->id);
        $blue = House::find($blue->id);
        app(TenantManager::class)->forget();

        $this->assertFalse($red->is_active);
        $this->assertFalse($blue->is_active, 'already retired, should stay retired');
    }

    public function test_bulk_reactivate_brings_selected_houses_back(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwenty');

        app(TenantManager::class)->use($school);
        $red = House::create(['name' => 'Red House', 'is_active' => false]);
        $blue = House::create(['name' => 'Blue House', 'is_active' => false]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/bulk-action", [
            'house_ids' => [$red->id, $blue->id],
            'action' => 'reactivate',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $red = House::find($red->id);
        $blue = House::find($blue->id);
        app(TenantManager::class)->forget();

        $this->assertTrue($red->is_active);
        $this->assertTrue($blue->is_active);
    }

    public function test_bulk_delete_unassigns_members_of_every_selected_house(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwentyone');

        app(TenantManager::class)->use($school);
        $red = House::create(['name' => 'Red House']);
        $blue = House::create(['name' => 'Blue House']);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'house_id' => $red->id]);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/bulk-action", [
            'house_ids' => [$red->id, $blue->id],
            'action' => 'delete',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $count = House::count();
        $studentAfter = Student::find($studentId);
        app(TenantManager::class)->forget();

        $this->assertSame(0, $count);
        $this->assertNotNull($studentAfter, 'the student must still exist');
        $this->assertNull($studentAfter->house_id, 'members of a bulk deleted house must be unassigned, not deleted');
    }

    public function test_bulk_action_requires_at_least_one_house_selected(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwentytwo');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/bulk-action", [
            'house_ids' => [],
            'action' => 'retire',
        ])->assertSessionHasErrors('house_ids');
    }

    public function test_a_teacher_without_manage_settings_cannot_bulk_act_on_houses(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'housetwentythree');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/houses/bulk-action", [
            'house_ids' => [$house->id],
            'action' => 'retire',
        ])->assertForbidden();
    }

    public function test_a_teacher_without_manage_settings_cannot_manage_houses(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'houseeight');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/houses", ['name' => 'Red House'])
            ->assertForbidden();
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/houses/apply-defaults")
            ->assertForbidden();
    }

    public function test_a_teacher_can_still_view_houses_and_download_the_template(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'housenine');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/houses")->assertOk();
        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/houses/bulk-template")->assertOk();
    }

    /** House Master and Assistant House Master, both chosen from the teacher list (user directive 2026-07-12). */
    public function test_admin_can_set_house_master_and_assistant(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwentyfour');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $master = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        $assistant = Staff::create(['staff_id' => 'STF/0002', 'first_name' => 'Ada', 'last_name' => 'Nwosu', 'status' => 'active']);
        $houseId = $house->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/{$houseId}/leadership", [
            'housemaster_staff_id' => $master->id,
            'assistant_housemaster_staff_id' => $assistant->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = House::find($houseId);
        app(TenantManager::class)->forget();

        $this->assertSame($master->id, $updated->housemaster_staff_id);
        $this->assertSame($assistant->id, $updated->assistant_housemaster_staff_id);
    }

    /** A sponsor can be an existing parent. */
    public function test_admin_can_add_a_parent_as_a_sponsor(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwentyfive');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $guardian = StudentGuardian::create(['student_id' => $student->id, 'name' => 'Mrs. Obi']);
        $houseId = $house->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/{$houseId}/sponsors", [
            'student_guardian_id' => $guardian->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $sponsor = HouseSponsor::where('house_id', $houseId)->first();
        app(TenantManager::class)->forget();

        $this->assertSame($guardian->id, $sponsor->student_guardian_id);
        $this->assertSame('Mrs. Obi', $sponsor->displayName());
    }

    /** A sponsor can also be typed in freehand when they are not a parent (user directive 2026-07-12). */
    public function test_admin_can_add_a_free_typed_sponsor_who_is_not_a_parent(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwentysix');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $houseId = $house->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/{$houseId}/sponsors", [
            'name' => 'Chief Adewale Okon',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $sponsor = HouseSponsor::where('house_id', $houseId)->first();
        app(TenantManager::class)->forget();

        $this->assertNull($sponsor->student_guardian_id);
        $this->assertSame('Chief Adewale Okon', $sponsor->displayName());
    }

    public function test_adding_a_sponsor_with_neither_a_parent_nor_a_name_is_rejected(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwentyseven');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $houseId = $house->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/{$houseId}/sponsors", [])
            ->assertStatus(422);
    }

    public function test_admin_can_remove_a_sponsor(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwentyeight');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $sponsor = HouseSponsor::create(['house_id' => $house->id, 'name' => 'Chief Adewale Okon']);
        $houseId = $house->id;
        $sponsorId = $sponsor->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->delete("http://{$school->slug}.localhost/houses/{$houseId}/sponsors/{$sponsorId}")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $gone = HouseSponsor::find($sponsorId);
        app(TenantManager::class)->forget();
        $this->assertNull($gone);
    }

    /** Members are grouped into Students, Teachers, and Parents (user directive 2026-07-12). */
    public function test_the_house_page_groups_members_into_students_teachers_and_parents(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housetwentynine');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'house_id' => $house->id]);
        Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active', 'house_id' => $house->id]);
        $student2 = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        StudentGuardian::create(['student_id' => $student2->id, 'name' => 'Mrs. Ade', 'house_id' => $house->id]);
        $houseId = $house->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/houses/{$houseId}")
            ->assertOk()
            ->assertSee('Students (1)', false)
            ->assertSee('Teachers (1)', false)
            ->assertSee('Parents (1)', false)
            ->assertSee('Ada Obi')
            ->assertSee('Tolu Bello')
            ->assertSee('Mrs. Ade');
    }

    /** A staff member's house can be changed from the house page (user directive 2026-07-12). */
    public function test_admin_can_move_a_staff_member_into_a_house(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housethirty');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/members/staff/{$staff->id}", [
            'house_id' => $house->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = Staff::find($staff->id);
        app(TenantManager::class)->forget();
        $this->assertSame($house->id, $updated->house_id);
    }

    /** A parent's house can be changed from the house page (user directive 2026-07-12). */
    public function test_admin_can_move_a_parent_into_a_house(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housethirtyone');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $guardian = StudentGuardian::create(['student_id' => $student->id, 'name' => 'Mrs. Obi']);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/members/parents/{$guardian->id}", [
            'house_id' => $house->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = StudentGuardian::find($guardian->id);
        app(TenantManager::class)->forget();
        $this->assertSame($house->id, $updated->house_id);
    }

    /**
     * Distribute every not-yet-in-a-house staff member and parent evenly
     * across active houses (user directive 2026-07-12: "Share all staff
     * and parents into the houses under each school"). Already assigned
     * members must be left untouched, and a retired house must never
     * receive anyone new.
     */
    public function test_distribute_members_spreads_unassigned_staff_and_parents_across_active_houses_only(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housethirtytwo');

        app(TenantManager::class)->use($school);
        $red = House::create(['name' => 'Red House']);
        $blue = House::create(['name' => 'Blue House']);
        $retired = House::create(['name' => 'Grey House', 'is_active' => false]);

        $alreadyPlaced = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Already', 'last_name' => 'Placed', 'status' => 'active', 'house_id' => $blue->id]);
        $staffOne = Staff::create(['staff_id' => 'STF/0002', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        $staffTwo = Staff::create(['staff_id' => 'STF/0003', 'first_name' => 'Ada', 'last_name' => 'Nwosu', 'status' => 'active']);

        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        $guardian = StudentGuardian::create(['student_id' => $student->id, 'name' => 'Mrs. Ade']);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/distribute-members")
            ->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $alreadyPlacedAfter = Staff::find($alreadyPlaced->id);
        $staffOneAfter = Staff::find($staffOne->id);
        $staffTwoAfter = Staff::find($staffTwo->id);
        $guardianAfter = StudentGuardian::find($guardian->id);
        $studentAfter = Student::find($student->id);
        app(TenantManager::class)->forget();

        $this->assertSame($blue->id, $alreadyPlacedAfter->house_id, 'an already placed staff member must not be moved');
        $this->assertContains($staffOneAfter->house_id, [$red->id, $blue->id]);
        $this->assertContains($staffTwoAfter->house_id, [$red->id, $blue->id]);
        $this->assertNotEquals($retired->id, $staffOneAfter->house_id);
        $this->assertNotEquals($retired->id, $staffTwoAfter->house_id);
        $this->assertContains($guardianAfter->house_id, [$red->id, $blue->id]);
        $this->assertContains($studentAfter->house_id, [$red->id, $blue->id], 'distribute must also place students, not just staff and parents');
    }

    public function test_distribute_members_requires_at_least_one_active_house(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housethirtythree');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/distribute-members")
            ->assertRedirect()->assertSessionHas('error');
    }

    /** Staff CSV export/import (user directive 2026-07-12: "add the import and export feature under the house section too"). */
    public function test_staff_bulk_template_is_prefilled_and_upload_assigns_a_house(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housethirtyfive');

        app(TenantManager::class)->use($school);
        $red = House::create(['name' => 'Red House']);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        app(TenantManager::class)->forget();

        $template = $this->actingAs($admin)->get("http://{$school->slug}.localhost/houses/staff-bulk-template");
        $template->assertOk();
        $this->assertStringContainsString('STF/0001', $template->getContent());
        $this->assertStringContainsString('Tolu Bello', $template->getContent());

        $csv = "staff_id,name,house\nSTF/0001,Tolu Bello,Red House\n";
        $file = UploadedFile::fake()->createWithContent('staff-houses.csv', $csv);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/staff-bulk-assign", ['file' => $file])
            ->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $updated = Staff::find($staff->id);
        app(TenantManager::class)->forget();
        $this->assertSame($red->id, $updated->house_id);
    }

    /** Parent CSV export/import, keyed on the child's admission number plus the parent's name (no standalone Guardian entity to key on directly). */
    public function test_parents_bulk_template_is_prefilled_and_upload_assigns_a_house(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housethirtysix');

        app(TenantManager::class)->use($school);
        $red = House::create(['name' => 'Red House']);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $guardian = StudentGuardian::create(['student_id' => $student->id, 'name' => 'Mrs. Obi']);
        app(TenantManager::class)->forget();

        $template = $this->actingAs($admin)->get("http://{$school->slug}.localhost/houses/parents-bulk-template");
        $template->assertOk();
        $this->assertStringContainsString('A1', $template->getContent());
        $this->assertStringContainsString('Mrs. Obi', $template->getContent());

        $csv = "student_admission_number,parent_name,house\nA1,Mrs. Obi,Red House\n";
        $file = UploadedFile::fake()->createWithContent('parent-houses.csv', $csv);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/parents-bulk-assign", ['file' => $file])
            ->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $updated = StudentGuardian::find($guardian->id);
        app(TenantManager::class)->forget();
        $this->assertSame($red->id, $updated->house_id);
    }

    public function test_a_teacher_without_manage_settings_cannot_set_house_leadership(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'housethirtyfour');

        app(TenantManager::class)->use($school);
        $house = House::create(['name' => 'Red House']);
        $houseId = $house->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/houses/{$houseId}/leadership", [])
            ->assertForbidden();
    }
}
