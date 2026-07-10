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

    public function test_admin_can_add_a_house_and_a_student_can_be_assigned_manually(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'housethree');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses", ['name' => 'Red House'])->assertRedirect();

        app(TenantManager::class)->use($school);
        $house = House::where('name', 'Red House')->first();
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/houses/assign/{$studentId}", [
            'house_id' => $house->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = Student::find($studentId);
        app(TenantManager::class)->forget();
        $this->assertSame($house->id, $updated->house_id);
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
