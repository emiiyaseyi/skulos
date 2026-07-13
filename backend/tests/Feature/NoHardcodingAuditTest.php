<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\User;
use App\Services\Platform\SupportContactService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the "no-hardcoding audit done now, not deferred"
 * pass (2026-07-13): the platform support contact used to be
 * config('skulos.support')/env only, unchangeable without a code deploy,
 * and the optional modules picker on Billing/School Admin screens read
 * config('skulos.modules') live even though a seeded `modules` DB table
 * already existed for exactly this.
 */
class NoHardcodingAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_with_nothing_configured_the_env_fallback_applies(): void
    {
        $effective = app(SupportContactService::class)->effective();

        $this->assertSame(config('skulos.support.email'), $effective['email']);
        $this->assertSame(config('skulos.support.phone'), $effective['phone']);
    }

    public function test_platform_admin_can_change_the_support_contact(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.support-contact.update'), [
            'email' => 'help@skulos.app', 'phone' => '+234 800 000 0000',
        ])->assertRedirect();

        $effective = app(SupportContactService::class)->effective();
        $this->assertSame('help@skulos.app', $effective['email']);
        $this->assertSame('+234 800 000 0000', $effective['phone']);
    }

    public function test_the_login_page_reflects_the_admin_configured_support_contact(): void
    {
        app(SupportContactService::class)->saveSettings([
            'email' => 'help@skulos.app', 'phone' => '+234 800 000 0000',
        ]);

        $this->get('http://localhost/login')
            ->assertSee('help@skulos.app')
            ->assertSee('+234 800 000 0000');
    }

    public function test_a_non_super_admin_cannot_change_the_support_contact(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.settings.support-contact.edit'))->assertNotFound();
    }

    public function test_optional_modules_reads_the_seeded_db_catalog_not_config_directly(): void
    {
        $this->seed(RbacSeeder::class);

        $fromConfig = collect(config('skulos.modules'))->filter(fn ($m) => ! $m['core'])->keys()->sort()->values();
        $fromDb = collect(Module::optional())->keys()->sort()->values();

        $this->assertSame($fromConfig->all(), $fromDb->all());

        // Prove it is really reading the DB, not silently falling back to
        // config: renaming a module in the DB only (no config change) must
        // show up in Module::optional()'s output.
        $anyOptional = Module::where('is_core', false)->first();
        $anyOptional->update(['name' => 'Renamed For Test']);

        $this->assertSame('Renamed For Test', Module::optional()[$anyOptional->code]['name']);
    }
}
