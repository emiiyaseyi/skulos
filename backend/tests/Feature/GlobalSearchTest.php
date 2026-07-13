<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Global Search (backlog item, "To Do List 2" doc: "one search bar, search
 * anything"). Covers what the product actually has records of: Students,
 * Staff, and Parents/Guardians. Each category respects its own view
 * permission.
 */
class GlobalSearchTest extends TestCase
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

    public function test_owner_can_search_students_staff_and_guardians(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'searchone');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/900', 'first_name' => 'Zenith', 'last_name' => 'Okoro']);
        Staff::create(['staff_id' => 'STF/900', 'first_name' => 'Zenith', 'last_name' => 'Balogun', 'position' => 'Teacher']);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Zenith Guardian', 'email' => 'zenith@example.com']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/search?q=Zenith")
            ->assertOk()
            ->assertSee('Zenith Okoro')
            ->assertSee('Zenith Balogun')
            ->assertSee('Zenith Guardian');
    }

    public function test_a_short_query_asks_for_more_characters_rather_than_erroring(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'searchtwo');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/search?q=A")
            ->assertOk()->assertSee('at least 2 characters');
    }

    public function test_a_role_without_staff_view_never_sees_staff_results(): void
    {
        [$school, $registrar] = $this->schoolWithMember('registrar', 'searchthree');

        app(TenantManager::class)->use($school);
        Staff::create(['staff_id' => 'STF/901', 'first_name' => 'Hidden', 'last_name' => 'Person', 'position' => 'Teacher']);
        Student::create(['admission_number' => 'ADM/901', 'first_name' => 'Hidden', 'last_name' => 'Kid']);
        app(TenantManager::class)->forget();

        $this->actingAs($registrar)->get("http://{$school->slug}.localhost/search?q=Hidden")
            ->assertOk()
            ->assertSee('Hidden Kid')
            ->assertDontSee('Hidden Person');
    }

    public function test_search_input_appears_in_the_appbar_for_school_portal_pages(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'searchfour');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('Search students, staff, parents');
    }
}
