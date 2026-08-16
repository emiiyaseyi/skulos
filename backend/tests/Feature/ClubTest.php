<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Club;
use App\Models\Tenant\Setting;
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
 * Clubs (2026-08-16). Mirrors houses on purpose, so these tests cover
 * the parts where "the same as houses" could quietly not be true: the
 * optional on/off switch (houses have no equivalent), the CSV round
 * trip's refusal to wipe blanks, and the parent/student lockout that
 * houses only got after it was reported live.
 */
class ClubTest extends TestCase
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

    protected function schoolWithRole(string $slug, string $roleSlug = 'school-owner'): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = app(Rbac::class)->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    protected function makeStudent(School $school, string $admissionNo): Student
    {
        return app(TenantManager::class)->connectTo($school, fn () => Student::create([
            'first_name' => 'Test',
            'last_name' => 'Student'.$admissionNo,
            'admission_number' => $admissionNo,
            'gender' => 'Female',
            'status' => Student::STATUS_ACTIVE,
        ]));
    }

    public function test_the_standard_club_catalog_loads_in_one_click(): void
    {
        [$school, $owner] = $this->schoolWithRole('clubone');

        $this->actingAs($owner)
            ->post("http://{$school->slug}.localhost/clubs/apply-defaults")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(count(config('skulos.default_clubs')), Club::count());
        $this->assertNotNull(Club::where('name', 'JETS Club')->first());
        app(TenantManager::class)->forget();
    }

    public function test_clubs_are_hidden_until_a_school_turns_them_on(): void
    {
        [$school, $owner] = $this->schoolWithRole('clubtwo');

        app(TenantManager::class)->use($school);
        $this->assertFalse((bool) Setting::grab('clubs_enabled', false), 'Clubs should be off by default.');
        app(TenantManager::class)->forget();

        $this->actingAs($owner)
            ->post("http://{$school->slug}.localhost/clubs/toggle-enabled", ['enabled' => 1])
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertTrue((bool) Setting::grab('clubs_enabled', false));
        app(TenantManager::class)->forget();
    }

    public function test_auto_assign_only_fills_members_who_have_no_club(): void
    {
        [$school, $owner] = $this->schoolWithRole('clubthree');
        $a = $this->makeStudent($school, 'CLB/0001');
        $b = $this->makeStudent($school, 'CLB/0002');

        app(TenantManager::class)->use($school);
        $chosen = Club::create(['name' => 'Press Club']);
        Club::create(['name' => 'Mathematics Club']);
        // One student is placed deliberately by an admin.
        $a->update(['club_id' => $chosen->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/clubs/distribute-members");

        app(TenantManager::class)->use($school);
        // The deliberate placement survives; the unassigned one is filled.
        $this->assertSame($chosen->id, $a->fresh()->club_id, 'Auto assign overwrote a deliberate placement.');
        $this->assertNotNull($b->fresh()->club_id);
        app(TenantManager::class)->forget();
    }

    public function test_a_blank_club_cell_in_an_upload_leaves_that_student_alone(): void
    {
        [$school, $owner] = $this->schoolWithRole('clubfour');
        $keep = $this->makeStudent($school, 'CLB/1001');
        $change = $this->makeStudent($school, 'CLB/1002');

        app(TenantManager::class)->use($school);
        $press = Club::create(['name' => 'Press Club']);
        $keep->update(['club_id' => $press->id]);
        app(TenantManager::class)->forget();

        // Row one leaves the club column blank; it must not be wiped.
        $csv = "admission_number,class,club\nCLB/1001,,\nCLB/1002,,Press Club\n";
        // A real temp file with a real csv mime type. A faked upload is
        // rejected by the mimes:csv rule, which would make the "a blank
        // cell changed nothing" assertion below pass for entirely the
        // wrong reason: nothing processed at all.
        $path = tempnam(sys_get_temp_dir(), 'clubs').'.csv';
        file_put_contents($path, $csv);
        $file = new UploadedFile($path, 'clubs.csv', 'text/csv', null, true);

        $this->actingAs($owner)
            ->post("http://{$school->slug}.localhost/clubs/bulk-upload", ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame($press->id, $keep->fresh()->club_id, 'A blank cell wiped an existing assignment.');
        $this->assertSame($press->id, $change->fresh()->club_id);
        app(TenantManager::class)->forget();
    }

    public function test_deleting_a_club_releases_its_members_rather_than_deleting_them(): void
    {
        [$school, $owner] = $this->schoolWithRole('clubfive');
        $student = $this->makeStudent($school, 'CLB/2001');

        app(TenantManager::class)->use($school);
        $club = Club::create(['name' => 'Drama & Cultural Troupe']);
        $student->update(['club_id' => $club->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete("http://{$school->slug}.localhost/clubs/{$club->id}");

        app(TenantManager::class)->use($school);
        $this->assertNull(Club::find($club->id));
        $this->assertNotNull($student->fresh(), 'Deleting a club deleted a student.');
        $this->assertNull($student->fresh()->club_id);
        app(TenantManager::class)->forget();
    }

    public function test_a_parent_cannot_open_the_clubs_admin_screen(): void
    {
        // Parents hold students.view for their own child, which without
        // an explicit block would let them open a school-wide screen
        // complete with bulk assignment downloads -- the exact exposure
        // that was reported live on Houses.
        [$school, $parent] = $this->schoolWithRole('clubsix', 'parent');

        $this->actingAs($parent)
            ->get("http://{$school->slug}.localhost/clubs")
            ->assertForbidden();
    }
}
