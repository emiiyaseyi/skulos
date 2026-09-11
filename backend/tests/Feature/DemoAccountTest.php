<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * "Demo account" support (2026-08-15): a school_user row flagged
 * is_demo_readonly can never make any change, including its own
 * password, even though it's a completely ordinary authenticated
 * member otherwise. Scoped per school-membership, not the User itself.
 */
class DemoAccountTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_a_demo_account_cannot_change_its_own_password(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'demoschool']));
        $user = User::factory()->create(['password' => bcrypt('original-pass')]);
        $role = app(Rbac::class)->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active', 'is_demo_readonly' => true]);

        $response = $this->actingAs($user)->post("http://{$school->slug}.localhost/password/change", [
            'current_password' => 'original-pass',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('original-pass', $user->fresh()->password));
    }

    public function test_a_demo_account_can_still_view_pages_normally(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'demoschool2']));
        $user = User::factory()->create();
        $role = app(Rbac::class)->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active', 'is_demo_readonly' => true]);

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk();
    }

    public function test_an_ordinary_non_demo_account_can_still_change_its_own_password(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'demoschool3']));
        $user = User::factory()->create(['password' => bcrypt('original-pass')]);
        $role = app(Rbac::class)->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user)->post("http://{$school->slug}.localhost/password/change", [
            'current_password' => 'original-pass',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-new-password', $user->fresh()->password));
    }

    /**
     * User report 2026-09-11: "even though its a demo account, one
     * should be able to switch to the other account so that one can
     * also view what the other role can see." Switching which held role
     * is active writes only a session value, never a real school
     * record, so it's deliberately exempted from block_demo_mutations
     * (routes/web.php) even though it's a POST.
     */
    public function test_a_demo_account_can_still_switch_between_its_held_roles(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'demoschool5']));
        $user = User::factory()->create();
        $role = app(Rbac::class)->cloneTemplateRole($school, 'parent');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active', 'is_demo_readonly' => true]);

        $teacherRole = app(Rbac::class)->cloneTemplateRole($school, 'class-teacher');
        \App\Models\UserAdditionalRole::create(['school_id' => $school->id, 'user_id' => $user->id, 'role_id' => $teacherRole->id]);

        $response = $this->actingAs($user)->post("http://{$school->slug}.localhost/session/switch-role", ['role_id' => $teacherRole->id]);

        $response->assertRedirect()->assertSessionMissing('error');

        $fresh = User::find($user->id);
        app(TenantManager::class)->use($school);
        $active = $fresh->roleIn($school);
        app(TenantManager::class)->forget();
        $this->assertSame('class-teacher', $active?->slug);
    }

    public function test_the_demo_flag_is_scoped_to_one_school_membership_only(): void
    {
        $demoSchool = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'demoschool4']));
        $realSchool = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'realschool4']));
        $user = User::factory()->create(['password' => bcrypt('original-pass')]);

        $demoRole = app(Rbac::class)->cloneTemplateRole($demoSchool, 'class-teacher');
        $realRole = app(Rbac::class)->cloneTemplateRole($realSchool, 'class-teacher');
        $demoSchool->users()->attach($user->id, ['role_id' => $demoRole->id, 'status' => 'active', 'is_demo_readonly' => true]);
        $realSchool->users()->attach($user->id, ['role_id' => $realRole->id, 'status' => 'active']);

        $this->actingAs($user)->post("http://{$realSchool->slug}.localhost/password/change", [
            'current_password' => 'original-pass',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-new-password', $user->fresh()->password));
    }
}
