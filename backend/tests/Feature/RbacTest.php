<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->rbac = app(Rbac::class);
    }

    protected function schoolWithMember(string $roleSlug): array
    {
        $school = School::factory()->create();
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);

        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user, $role];
    }

    public function test_seeder_builds_catalog_and_is_idempotent(): void
    {
        $this->assertSame(count(config('skulos.modules')), Module::count());
        $this->assertSame(count(config('skulos.roles')), Role::whereNull('school_id')->count());
        $this->assertTrue(Permission::where('code', 'students.view')->exists());
        $this->assertTrue(Permission::where('code', 'examinations.approve_results')->exists());

        $before = [Module::count(), Permission::count(), Role::count()];
        $this->seed(RbacSeeder::class);
        $this->assertSame($before, [Module::count(), Permission::count(), Role::count()]);
    }

    public function test_role_grants_decide_access(): void
    {
        [$school, $teacher] = $this->schoolWithMember('teacher');

        $this->assertTrue($this->rbac->check($teacher, $school, 'attendance.create'));
        $this->assertFalse($this->rbac->check($teacher, $school, 'students.delete'));
    }

    public function test_user_deny_override_beats_role_grant(): void
    {
        [$school, $teacher] = $this->schoolWithMember('teacher');

        $this->rbac->overridePermission($school, $teacher, 'attendance.create', 'deny');

        $this->assertFalse($this->rbac->check($teacher, $school, 'attendance.create'));
    }

    public function test_user_allow_override_grants_beyond_role(): void
    {
        [$school, $teacher] = $this->schoolWithMember('teacher');

        $this->assertFalse($this->rbac->check($teacher, $school, 'students.bulk'));

        $this->rbac->overridePermission($school, $teacher, 'students.bulk', 'allow');

        $this->assertTrue($this->rbac->check($teacher, $school, 'students.bulk'));
    }

    public function test_super_admin_bypasses_everything(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->assertTrue($this->rbac->check($admin, $school, 'students.delete'));
    }

    public function test_non_members_and_unknown_codes_are_denied(): void
    {
        [$school, $teacher] = $this->schoolWithMember('teacher');
        $stranger = User::factory()->create();

        $this->assertFalse($this->rbac->check($stranger, $school, 'attendance.create'));
        $this->assertFalse($this->rbac->check($teacher, $school, 'no.such_permission'));
    }

    public function test_suspended_membership_is_denied(): void
    {
        [$school, $teacher] = $this->schoolWithMember('teacher');

        $school->users()->updateExistingPivot($teacher->id, ['status' => 'suspended']);

        $this->assertFalse($this->rbac->check($teacher, $school, 'attendance.create'));
    }

    public function test_module_state_defaults_follow_catalog(): void
    {
        $school = School::factory()->create();

        $this->assertSame(Module::STATE_ENABLED, $this->rbac->moduleState($school, 'students'));
        $this->assertSame(Module::STATE_DISABLED, $this->rbac->moduleState($school, 'library'));
        $this->assertSame(Module::STATE_DISABLED, $this->rbac->moduleState($school, 'not_a_module'));
    }

    public function test_module_state_precedence_user_over_role_over_school(): void
    {
        [$school, $teacher, $role] = $this->schoolWithMember('teacher');

        $this->rbac->setModuleState($school, 'library', Module::STATE_ENABLED); // school scope
        $this->assertSame(Module::STATE_ENABLED, $this->rbac->moduleState($school, 'library', $teacher));

        $this->rbac->setModuleState($school, 'library', Module::STATE_READONLY, 'role', $role->id);
        $this->assertSame(Module::STATE_READONLY, $this->rbac->moduleState($school, 'library', $teacher));

        $this->rbac->setModuleState($school, 'library', Module::STATE_HIDDEN, 'user', $teacher->id);
        $this->assertSame(Module::STATE_HIDDEN, $this->rbac->moduleState($school, 'library', $teacher));

        // Another member of the same school is unaffected by user-scope state.
        $other = User::factory()->create();
        $school->users()->attach($other->id, ['role_id' => null, 'status' => 'active']);
        $this->assertSame(Module::STATE_ENABLED, $this->rbac->moduleState($school, 'library', $other));
    }

    public function test_cloned_template_role_is_school_owned_and_carries_permissions(): void
    {
        $school = School::factory()->create();

        $role = $this->rbac->cloneTemplateRole($school, 'registrar');

        $this->assertSame($school->id, $role->school_id);
        $this->assertTrue($role->permissions()->where('code', 'students.view')->exists());

        // Cloning twice returns the same school-owned role, no duplicates.
        $again = $this->rbac->cloneTemplateRole($school, 'registrar');
        $this->assertTrue($role->is($again));
    }
}
