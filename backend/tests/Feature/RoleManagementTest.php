<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * User & Role Management (user directive 2026-07-15: "ALL THESE EXTRA
 * ACCESS SHOULD BE MOVED TO USER AND ROLE MANAGEMENT WITH MORE FEATURES TO
 * BE GIVEN TO A USER AND THE ADMIN CAN CREATE A NEW ROLE BY COMBINING MORE
 * FEATURES INTO ONE AND CAN ALSO ASSIGN A ROLE TO A USER ETC"). One
 * screen: create a custom role by combining modules, reassign which role
 * a user holds, and grant per-user extra access, relocated from the
 * individual staff profile page (see StaffPortalTest for the "moved, not
 * still there" side of this).
 */
class RoleManagementTest extends TestCase
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

    public function test_the_index_page_clones_every_template_role_so_the_full_catalog_is_assignable(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmone');

        $response = $this->actingAs($owner)->get(route('school.roles.index', ['school' => $school->slug]));
        $response->assertOk()->assertSee('School Owner')->assertSee('Bursar')->assertSee('Class Teacher');

        $this->assertGreaterThan(1, Role::where('school_id', $school->id)->count());
    }

    public function test_owner_can_create_a_custom_role_by_combining_modules(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmtwo');
        $this->actingAs($owner)->get(route('school.roles.index', ['school' => $school->slug])); // clones templates

        $this->actingAs($owner)->post(route('school.roles.store', ['school' => $school->slug]), [
            'name' => 'Admissions Officer',
            'modules' => ['students', 'communication'],
        ])->assertRedirect();

        $role = Role::where('school_id', $school->id)->where('name', 'Admissions Officer')->first();
        $this->assertNotNull($role);
        $this->assertTrue($role->permissions()->where('module', 'students')->exists());
        $this->assertTrue($role->permissions()->where('module', 'communication')->exists());
        $this->assertFalse($role->permissions()->where('module', 'examinations')->exists());
    }

    public function test_a_module_not_on_the_curated_list_cannot_be_used_to_create_a_role(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmthree');

        // "finance" has no real screen behind it yet, so it is not on the curated list.
        $this->actingAs($owner)->post(route('school.roles.store', ['school' => $school->slug]), [
            'name' => 'Finance Lead',
            'modules' => ['finance'],
        ])->assertSessionHasErrors('modules.0');
    }

    public function test_adding_modules_to_an_existing_role_is_additive_not_destructive(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmfour');
        $this->actingAs($owner)->get(route('school.roles.index', ['school' => $school->slug]));

        $bursar = Role::where('school_id', $school->id)->where('slug', 'bursar')->firstOrFail();
        $originalCount = $bursar->permissions()->count(); // bursar starts with students.view/parents.view/analytics.view only

        $this->actingAs($owner)->post(route('school.roles.modules.add', ['school' => $school->slug, 'roleId' => $bursar->id]), [
            'modules' => ['report_cards'],
        ])->assertRedirect();

        $bursar->refresh();
        $this->assertGreaterThan($originalCount, $bursar->permissions()->count());
        $this->assertTrue($bursar->permissions()->where('module', 'students')->exists(), 'the original grant must survive, this action only adds');
        $this->assertTrue($bursar->permissions()->where('module', 'report_cards')->exists());
    }

    public function test_owner_can_reassign_a_users_role(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmfive');
        [, $staffUser] = $this->schoolWithMember2($school, 'class-teacher');
        $this->actingAs($owner)->get(route('school.roles.index', ['school' => $school->slug])); // clones templates

        $newRole = Role::where('school_id', $school->id)->where('slug', 'subject-teacher')->firstOrFail();

        $this->actingAs($owner)->post(route('school.roles.users.assign', ['school' => $school->slug, 'userId' => $staffUser->id]), [
            'role_id' => $newRole->id,
        ])->assertRedirect();

        $this->assertSame($newRole->id, $staffUser->roleIn($school)->id);
    }

    public function test_owner_can_grant_and_revoke_extra_module_access(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmsix');
        [, $bursarUser] = $this->schoolWithMember2($school, 'bursar');

        $this->assertFalse($this->rbac->check($bursarUser, $school, 'report_cards.view'), 'a plain Bursar has no report card access by default');

        $this->actingAs($owner)->post(route('school.roles.users.extra-access', ['school' => $school->slug, 'userId' => $bursarUser->id]), [
            'modules' => ['report_cards'],
        ])->assertRedirect();

        $this->assertTrue($this->rbac->check($bursarUser, $school, 'report_cards.view'));
        $this->assertTrue($this->rbac->check($bursarUser, $school, 'report_cards.update'), 'grant is full module access, matching what a role\'s "module.*" grant would give');

        $this->actingAs($owner)->post(route('school.roles.users.extra-access', ['school' => $school->slug, 'userId' => $bursarUser->id]), [
            'modules' => [],
        ])->assertRedirect();

        $this->assertFalse($this->rbac->check($bursarUser, $school, 'report_cards.view'), 'unticking the box must revoke the extra access');
    }

    public function test_a_teacher_without_staff_update_cannot_open_the_page(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'rmseven');

        $this->actingAs($teacher)->get(route('school.roles.index', ['school' => $school->slug]))
            ->assertForbidden();
    }

    /** A second member of the same already-provisioned school, without re-provisioning it. */
    protected function schoolWithMember2(School $school, string $roleSlug): array
    {
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }
}
