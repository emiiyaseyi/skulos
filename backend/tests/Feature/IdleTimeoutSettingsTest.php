<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Security\IdleTimeoutService;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admin configurable idle sign out timeout (user request 2026-07-11:
 * "currently hardcoded to 30 seconds, the admins can change the seconds
 * or turn it off"). Same precedence as SMTP: a platform default, an
 * optional per-school override that wins for that school, config's
 * fallback if neither is set.
 */
class IdleTimeoutSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_with_nothing_configured_the_config_fallback_applies(): void
    {
        $effective = app(IdleTimeoutService::class)->effective();

        $this->assertTrue($effective['enabled']);
        $this->assertSame(30, $effective['idle_seconds']);
        $this->assertSame(15, $effective['warning_seconds']);
    }

    public function test_platform_admin_can_change_the_default_or_turn_it_off(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.idle-timeout.update'), [
            'enabled' => '1', 'idle_seconds' => 120, 'warning_seconds' => 20,
        ])->assertRedirect();

        $effective = app(IdleTimeoutService::class)->effective();
        $this->assertTrue($effective['enabled']);
        $this->assertSame(120, $effective['idle_seconds']);

        $this->actingAs($admin)->post(route('admin.settings.idle-timeout.update'), [
            'idle_seconds' => 120, 'warning_seconds' => 20, 'enabled' => '0',
        ]);

        $this->assertFalse(app(IdleTimeoutService::class)->effective()['enabled']);
    }

    public function test_a_schools_own_setting_overrides_the_platform_default(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'idleone']));
        $owner = User::factory()->create();
        $ownerRole = \App\Models\Role::whereNull('school_id')->where('slug', 'school-owner')->first();
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $this->actingAs($admin)->post(route('admin.settings.idle-timeout.update'), [
            'idle_seconds' => 60, 'warning_seconds' => 10,
        ]);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/idle-timeout", [
            'use_own' => '1', 'enabled' => '1', 'idle_seconds' => 300, 'warning_seconds' => 30,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $effective = app(IdleTimeoutService::class)->effective();
        app(TenantManager::class)->forget();

        $this->assertSame(300, $effective['idle_seconds']);
    }

    public function test_a_school_can_switch_back_to_using_the_platform_default(): void
    {
        $this->seed(RbacSeeder::class);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'idletwo']));
        $owner = User::factory()->create();
        $ownerRole = \App\Models\Role::whereNull('school_id')->where('slug', 'school-owner')->first();
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/idle-timeout", [
            'use_own' => '1', 'enabled' => '1', 'idle_seconds' => 300, 'warning_seconds' => 30,
        ]);
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/idle-timeout", [
            'use_own' => '0',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $effective = app(IdleTimeoutService::class)->effective();
        app(TenantManager::class)->forget();

        $this->assertSame(30, $effective['idle_seconds'], 'must fall back to the config default once the school override is cleared');
    }

    public function test_a_non_super_admin_cannot_change_the_platform_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.settings.idle-timeout.edit'))->assertNotFound();
    }
}
