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

    /**
     * User directive 2026-07-16: "CHECK THE BILLING FEATURES AGAIN WHERE
     * WE HAVE SO MANY MAIN SECTIONS AND SUB SECTION WITH ALL THE FEATURES
     * UNDER EACH SECTION... SO ONE CAN SELECT FROM MULTIPLE SECTIONS TO
     * CREATE A NEW ROLE". A role is now built from individual granular
     * permissions (one module's real Permission rows = one section), not
     * a coarse whole-module toggle.
     */
    public function test_owner_can_create_a_custom_role_by_combining_individual_permissions_across_sections(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmtwo');
        $this->actingAs($owner)->get(route('school.roles.index', ['school' => $school->slug])); // clones templates

        $studentsView = \App\Models\Permission::where('code', 'students.view')->firstOrFail();
        $commsView = \App\Models\Permission::where('code', 'communication.view')->firstOrFail();

        $this->actingAs($owner)->post(route('school.roles.store', ['school' => $school->slug]), [
            'name' => 'Admissions Officer',
            'permissions' => [$studentsView->id, $commsView->id],
        ])->assertRedirect();

        $role = Role::where('school_id', $school->id)->where('name', 'Admissions Officer')->first();
        $this->assertNotNull($role);
        $this->assertSame(2, $role->permissions()->count());
        $this->assertTrue($role->permissions()->where('code', 'students.view')->exists());
        $this->assertTrue($role->permissions()->where('code', 'communication.view')->exists());
        // Only the ticked permission from the "students" section, not
        // every one of that module's permissions, matching the granular
        // (not whole-module) grant this redesign introduced.
        $this->assertFalse($role->permissions()->where('code', 'students.create')->exists());
    }

    public function test_a_permission_not_on_the_curated_list_cannot_be_used_to_create_a_role(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmthree');

        // "library" has no real screen behind it yet, so it is not on the
        // curated list (this test used "finance" until 2026-07-17, when the
        // Foundation Finance slice gave it a real screen and moved it onto
        // config('skulos.staff_extra_access_modules'), the exact catalog
        // this test is about).
        $libraryView = \App\Models\Permission::where('code', 'library.view')->firstOrFail();

        $this->actingAs($owner)->post(route('school.roles.store', ['school' => $school->slug]), [
            'name' => 'Library Lead',
            'permissions' => [$libraryView->id],
        ])->assertSessionHasErrors('permissions.0');
    }

    /**
     * User directive 2026-07-16 supersedes the old additive-only design
     * for this screen: "ONE SHOULD BE ABLE TO CLICK ON THEM AND SEE WHAT
     * THEY HAVE ACCESS TO, ADD OR REMOVE". A permission unticked in the
     * submitted form is now genuinely removed from the role, not just
     * left alone; anything the role holds outside the curated catalog
     * (a permission this screen's checklist never offered a box for) is
     * still preserved either way.
     */
    public function test_editing_a_roles_permissions_can_both_add_and_remove(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmfour');
        $this->actingAs($owner)->get(route('school.roles.index', ['school' => $school->slug]));

        $bursar = Role::where('school_id', $school->id)->where('slug', 'bursar')->firstOrFail();
        $studentsView = \App\Models\Permission::where('code', 'students.view')->firstOrFail(); // bursar starts with this
        $reportCardsView = \App\Models\Permission::where('code', 'report_cards.view')->firstOrFail();

        $this->assertTrue($bursar->permissions()->where('permissions.id', $studentsView->id)->exists());
        $this->assertFalse($bursar->permissions()->where('permissions.id', $reportCardsView->id)->exists());

        // Submit a checklist that adds report_cards.view but leaves
        // students.view unticked: students.view must be removed, not
        // silently kept the way the old additive design would have.
        $this->actingAs($owner)->post(route('school.roles.permissions.update', ['school' => $school->slug, 'roleId' => $bursar->id]), [
            'permissions' => [$reportCardsView->id],
        ])->assertRedirect();

        $bursar->refresh();
        $this->assertTrue($bursar->permissions()->where('permissions.id', $reportCardsView->id)->exists());
        $this->assertFalse($bursar->permissions()->where('permissions.id', $studentsView->id)->exists(), 'unticking an existing permission must remove it');
    }

    /** A permission this role holds from OUTSIDE the curated catalog (never offered as a checkbox) must survive an edit either way. */
    public function test_editing_a_roles_permissions_never_touches_permissions_outside_the_curated_catalog(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmfourb');
        $this->actingAs($owner)->get(route('school.roles.index', ['school' => $school->slug]));

        $bursar = Role::where('school_id', $school->id)->where('slug', 'bursar')->firstOrFail();
        // library.view: genuinely outside both the curated catalog checkbox
        // list AND bursar's own template grants (unlike finance.view, which
        // this test originally used until 2026-07-17, when Bursar's own
        // template grants were extended to include finance.* for the
        // Foundation Finance slice, making finance.view a real grant for
        // this role rather than an "outside the catalog" example).
        $libraryView = \App\Models\Permission::where('code', 'library.view')->firstOrFail();
        $bursar->permissions()->attach($libraryView->id);

        $this->actingAs($owner)->post(route('school.roles.permissions.update', ['school' => $school->slug, 'roleId' => $bursar->id]), [
            'permissions' => [],
        ])->assertRedirect();

        $bursar->refresh();
        $this->assertTrue($bursar->permissions()->where('permissions.id', $libraryView->id)->exists(), 'a permission outside the curated catalog must never be touched by this screen');
    }

    /** User directive 2026-07-16: "I WANT TO CLICK ON A ROLE AND SEE ALL THE NAME OF USERS UNDER THE ROLE - THIS CAN COME AS POP UP". */
    public function test_the_index_page_carries_each_roles_member_names_for_the_popup(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rmfourc');
        [, $bursarUser] = $this->schoolWithMember2($school, 'bursar');
        $bursarUser->update(['name' => 'Bimbo Bursar']);

        $response = $this->actingAs($owner)->get(route('school.roles.index', ['school' => $school->slug]));
        $response->assertOk()->assertSee('Bimbo Bursar');
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
