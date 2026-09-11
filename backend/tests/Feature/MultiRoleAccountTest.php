<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Multi-role accounts (user directive 2026-09-11): "we have some users
 * that they are both teacher and parent, add a feature to the admin
 * setting that allows one account to serve as both parent and teacher...
 * allow the school admin to also be a class teacher or subject teacher,
 * or other roles... a switch account button at the top."
 */
class MultiRoleAccountTest extends TestCase
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

    protected function schoolWithOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $owner = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $owner];
    }

    protected function addTeacher(School $school, string $email): User
    {
        $teacher = User::factory()->create(['email' => $email]);
        $role = $this->rbac->cloneTemplateRole($school, 'teacher');
        $school->users()->attach($teacher->id, ['role_id' => $role->id, 'status' => 'active']);

        return $teacher;
    }

    public function test_admin_can_grant_an_additional_role_to_an_existing_member(): void
    {
        [$school, $owner] = $this->schoolWithOwner('mrone');
        $teacher = $this->addTeacher($school, 'teacher@mrone.test');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/multi-role-accounts/grant", [
            'user_id' => $teacher->id,
            'role_slug' => 'parent',
        ])->assertRedirect();

        $this->assertDatabaseHas('user_additional_roles', [
            'school_id' => $school->id,
            'user_id' => $teacher->id,
        ]);

        // The primary role must stay exactly what it was.
        $primaryRoleId = $school->users()->where('users.id', $teacher->id)->value('school_user.role_id');
        $teacherRole = Role::where('school_id', $school->id)->where('slug', 'teacher')->first();
        $this->assertSame($teacherRole->id, $primaryRoleId);
    }

    public function test_granting_a_teaching_scoped_role_auto_creates_a_linked_staff_record(): void
    {
        [$school, $owner] = $this->schoolWithOwner('mrtwo');

        $this->assertNull(\App\Models\Tenant\Staff::where('user_id', $owner->id)->first());

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/multi-role-accounts/grant", [
            'user_id' => $owner->id,
            'role_slug' => 'class-teacher',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $staff = Staff::where('user_id', $owner->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($staff, 'a Staff record should have been auto-created');
        $this->assertStringStartsWith('STF/', $staff->staff_id);
    }

    public function test_granting_a_non_teaching_role_does_not_create_a_staff_record(): void
    {
        [$school, $owner] = $this->schoolWithOwner('mrthree');
        $teacher = $this->addTeacher($school, 'teacher@mrthree.test');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/multi-role-accounts/grant", [
            'user_id' => $teacher->id,
            'role_slug' => 'bursar',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $staffCount = Staff::where('user_id', $teacher->id)->count();
        app(TenantManager::class)->forget();

        $this->assertSame(0, $staffCount);
    }

    public function test_switching_roles_changes_what_rbac_check_and_the_dashboard_allow(): void
    {
        [$school, $owner] = $this->schoolWithOwner('mrfour');
        $teacher = $this->addTeacher($school, 'teacher@mrfour.test');

        // Grant parent as an additional role.
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/multi-role-accounts/grant", [
            'user_id' => $teacher->id, 'role_slug' => 'parent',
        ])->assertRedirect();

        $teacher->forceFill(['must_change_password' => false])->save();

        // Before switching: primary role (teacher) is active. attendance
        // is a core module (always enabled), so this check is not at the
        // mercy of which optional modules a fresh test school happens to
        // have -- attendance.create is teacher-only, parent never holds it.
        app(TenantManager::class)->use($school);
        $this->assertTrue($this->rbac->check($teacher, $school, 'attendance.create'));
        app(TenantManager::class)->forget();

        $parentRole = Role::where('school_id', $school->id)->where('slug', 'parent')->first();
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/session/switch-role", [
            'role_id' => $parentRole->id,
        ])->assertRedirect();

        // After switching into parent (fresh User instance per request,
        // per roleIn()'s own cache-per-instance design): the teacher-only
        // permission no longer applies.
        $fresh = User::find($teacher->id);
        app(TenantManager::class)->use($school);
        $this->assertFalse($this->rbac->check($fresh, $school, 'attendance.create'));
        app(TenantManager::class)->forget();
    }

    public function test_cannot_switch_into_a_role_not_held(): void
    {
        [$school, $owner] = $this->schoolWithOwner('mrfive');
        $teacher = $this->addTeacher($school, 'teacher@mrfive.test');
        $teacher->forceFill(['must_change_password' => false])->save();

        // Bursar exists at this school (owner is bursar-capable via
        // wildcard, but teacher never held or was granted it).
        $bursarRole = $this->rbac->cloneTemplateRole($school, 'bursar');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/session/switch-role", [
            'role_id' => $bursarRole->id,
        ])->assertForbidden();

        $fresh = User::find($teacher->id);
        app(TenantManager::class)->use($school);
        $role = $fresh->roleIn($school);
        app(TenantManager::class)->forget();
        $this->assertSame('teacher', $role->slug, 'must still resolve to the primary role, not the rejected one');
    }

    public function test_revoking_the_currently_active_additional_role_falls_back_to_primary(): void
    {
        [$school, $owner] = $this->schoolWithOwner('mrsix');
        $teacher = $this->addTeacher($school, 'teacher@mrsix.test');
        $teacher->forceFill(['must_change_password' => false])->save();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/multi-role-accounts/grant", [
            'user_id' => $teacher->id, 'role_slug' => 'parent',
        ])->assertRedirect();
        $parentRole = Role::where('school_id', $school->id)->where('slug', 'parent')->first();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/session/switch-role", [
            'role_id' => $parentRole->id,
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/multi-role-accounts/revoke", [
            'user_id' => $teacher->id, 'role_id' => $parentRole->id,
        ])->assertRedirect();

        // The next request for this user resolves back to primary, no error.
        $fresh = User::find($teacher->id);
        app(TenantManager::class)->use($school);
        $role = $fresh->roleIn($school);
        app(TenantManager::class)->forget();
        $this->assertSame('teacher', $role->slug);
    }

    public function test_a_teacher_without_the_permission_cannot_grant_a_role(): void
    {
        [$school, ] = $this->schoolWithOwner('mrseven');
        $teacher = $this->addTeacher($school, 'teacher@mrseven.test');
        $other = $this->addTeacher($school, 'other@mrseven.test');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/multi-role-accounts/grant", [
            'user_id' => $other->id, 'role_slug' => 'parent',
        ])->assertForbidden();
    }

    /**
     * The literal root-cause bug this whole feature traces back to: the
     * SAME real person provisioned once as a teacher, then later as a
     * parent (matching email), used to silently lose the teacher role.
     */
    public function test_provisioning_the_same_identity_under_a_second_role_does_not_overwrite_the_first(): void
    {
        [$school, ] = $this->schoolWithOwner('mreight');
        $accounts = app(\App\Services\Accounts\AccountProvisioningService::class);

        $first = $accounts->provisionAuto($school, 'Ada Bello', 'ada.bello@example.com', null, 'teacher', 'password123', 'synthetic-1@x.local');
        $second = $accounts->provisionAuto($school, 'Ada Bello', 'ada.bello@example.com', null, 'parent', 'password123', 'synthetic-2@x.local');

        $this->assertSame($first['user']->id, $second['user']->id, 'the same real identity must resolve to the same account');

        $primaryRoleId = $school->users()->where('users.id', $first['user']->id)->value('school_user.role_id');
        $teacherRole = Role::where('school_id', $school->id)->where('slug', 'teacher')->first();
        $this->assertSame($teacherRole->id, $primaryRoleId, 'the original teacher role must not have been overwritten');

        $this->assertDatabaseHas('user_additional_roles', [
            'school_id' => $school->id,
            'user_id' => $first['user']->id,
        ]);
    }
}
