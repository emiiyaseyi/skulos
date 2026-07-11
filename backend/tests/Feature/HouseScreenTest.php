<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\House;
use App\Models\Tenant\Student;
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
}
