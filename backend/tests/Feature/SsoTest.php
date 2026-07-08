<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\SsoManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use RefreshDatabase;

    protected function enable(string $provider): void
    {
        config([
            "skulos.sso.{$provider}.enabled" => true,
            "services.{$provider}.client_id" => 'test-client-id',
            "services.{$provider}.client_secret" => 'test-secret',
            "services.{$provider}.redirect" => 'http://localhost/auth/'.$provider.'/callback',
        ]);
    }

    protected function fakeIdentity(string $email, string $name = 'Test User'): SocialiteUser
    {
        $user = Mockery::mock(SocialiteUser::class);
        $user->shouldReceive('getEmail')->andReturn($email);
        $user->shouldReceive('getName')->andReturn($name);

        return $user;
    }

    public function test_provider_is_unavailable_unless_enabled_and_configured(): void
    {
        $sso = app(SsoManager::class);
        $this->assertFalse($sso->isAvailable('google'));   // off by default

        config(['skulos.sso.google.enabled' => true]);      // enabled but no creds
        $this->assertFalse($sso->isAvailable('google'));

        $this->enable('google');
        $this->assertTrue($sso->isAvailable('google'));
        $this->assertContains('google', $sso->available());

        $this->assertFalse($sso->isAvailable('nope'));      // unknown provider
    }

    public function test_disabled_provider_routes_return_404(): void
    {
        $this->get('/auth/google/redirect')->assertNotFound();
        $this->get('/auth/microsoft/callback')->assertNotFound();
    }

    public function test_callback_provisions_a_new_user_and_logs_them_in(): void
    {
        $this->enable('google');

        Socialite::shouldReceive('driver->user')
            ->andReturn($this->fakeIdentity('newteacher@example.com', 'New Teacher'));

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticated();
        $user = User::where('email', 'newteacher@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_super_admin);
    }

    public function test_sso_links_to_existing_account_by_email(): void
    {
        $this->enable('microsoft');
        $existing = User::factory()->create(['email' => 'parent@example.com']);

        Socialite::shouldReceive('driver->user')
            ->andReturn($this->fakeIdentity('parent@example.com', 'Parent'));

        $this->get('/auth/microsoft/callback')->assertRedirect('/');

        $this->assertSame(1, User::where('email', 'parent@example.com')->count());
        $this->assertAuthenticatedAs($existing);
    }

    public function test_sso_login_is_audited(): void
    {
        $this->enable('google');
        Socialite::shouldReceive('driver->user')
            ->andReturn($this->fakeIdentity('audited@example.com'));

        $this->get('/auth/google/callback');

        $this->assertTrue(AuditLog::where('action', 'sso.user_provisioned')->exists());
        $this->assertTrue(AuditLog::where('action', 'sso.login')->exists());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
