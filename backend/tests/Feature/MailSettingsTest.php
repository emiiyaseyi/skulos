<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Mail\MailSettingsService;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Admin configured SMTP (user request 2026-07-09): a platform wide
 * default, and an optional per-school override that wins while a
 * request is scoped to that school.
 */
class MailSettingsTest extends TestCase
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

    public function test_platform_admin_can_save_and_the_password_is_encrypted_at_rest(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.mail.update'), [
            'host' => 'smtp.mailtrap.io', 'port' => 587, 'username' => 'user1',
            'password' => 'super-secret', 'encryption' => 'tls',
            'from_address' => 'no-reply@skulos.app', 'from_name' => 'SKULOS',
        ])->assertRedirect();

        $this->assertDatabaseMissing('platform_settings', ['value' => 'super-secret']);

        $mail = app(MailSettingsService::class);
        $this->assertSame('super-secret', $mail->platformSettings()['password']);
        $this->assertSame('smtp.mailtrap.io', $mail->platformSettings()['host']);
    }

    public function test_leaving_the_password_blank_on_a_later_save_keeps_the_existing_one(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.mail.update'), [
            'host' => 'smtp.one.test', 'password' => 'first-pass',
        ]);
        $this->actingAs($admin)->post(route('admin.settings.mail.update'), [
            'host' => 'smtp.two.test', 'password' => '',
        ]);

        $settings = app(MailSettingsService::class)->platformSettings();
        $this->assertSame('smtp.two.test', $settings['host']);
        $this->assertSame('first-pass', $settings['password']);
    }

    public function test_a_schools_own_mail_settings_override_the_platform_default(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'ownmail']));
        $owner = User::factory()->create();
        $ownerRole = \App\Models\Role::whereNull('school_id')->where('slug', 'school-owner')->first();
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $this->actingAs($admin)->post(route('admin.settings.mail.update'), ['host' => 'smtp.platform.test']);
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/mail", ['host' => 'smtp.school.test']);

        $mail = app(MailSettingsService::class);
        $this->assertSame('smtp.platform.test', $mail->platformSettings()['host']);

        app(TenantManager::class)->use($school);
        $this->assertSame('smtp.school.test', $mail->schoolSettings()['host']);
        app(TenantManager::class)->forget();
    }

    public function test_send_test_email_works(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.mail.test'))->assertRedirect();

        Mail::assertSent(\App\Mail\TestMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }
}
