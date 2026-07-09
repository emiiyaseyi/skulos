<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PlatformFlowTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config([
            'tenancy.sqlite_path' => $this->tenantDir,
            'tenancy.driver' => 'sqlite',
            'app.domain' => 'localhost',
        ]);
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

    /** Provision a school directly (no HTTP, so the test session stays guest). */
    protected function provisionSchool(string $slug, string $name = 'A School'): School
    {
        return app(TenantManager::class)->provision(
            School::factory()->create(['slug' => $slug, 'name' => $name])
        );
    }

    public function test_make_super_admin_command(): void
    {
        $this->artisan('platform:make-super-admin', ['email' => 'boss@skulos.app', '--password' => 'secret-pass-123'])
            ->assertSuccessful();

        $user = User::where('email', 'boss@skulos.app')->first();
        $this->assertTrue($user->is_super_admin);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('http://localhost/admin')->assertRedirect('/login');
    }

    public function test_only_super_admin_reaches_admin_area(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->get('http://localhost/admin')->assertNotFound(); // 404, not advertised

        $this->actingAs($this->superAdmin())->get('http://localhost/admin')->assertOk()->assertSee('Platform Administration');
    }

    public function test_super_admin_can_create_a_school_which_gets_provisioned(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post('http://localhost/admin/schools', [
            'name' => 'Greenfield Academy',
            'slug' => 'greenfield',
        ])->assertRedirect(route('admin.dashboard'));

        $school = School::where('slug', 'greenfield')->first();
        $this->assertNotNull($school);
        $this->assertTrue($school->isProvisioned());
        $this->assertSame(School::STATUS_ACTIVE, $school->status);
        // creator attached as a member
        $this->assertTrue($admin->fresh()->isMemberOf($school));
    }

    public function test_school_welcome_page_renders_branding(): void
    {
        $school = $this->provisionSchool('bright', 'Bright Stars');
        $school->update(['welcome_headline' => 'Hello Bright Stars', 'brand_color' => '#1B87D6']);

        $this->get('http://bright.localhost/')
            ->assertOk()->assertSee('Hello Bright Stars')->assertSee('#1B87D6');
    }

    public function test_school_member_can_sign_in_and_see_dashboard(): void
    {
        $school = $this->provisionSchool('unity', 'Unity');
        $teacher = User::factory()->create(['password' => bcrypt('teachpass123')]);
        $school->users()->attach($teacher->id, ['status' => 'active']);

        $this->post('http://unity.localhost/login', [
            'email' => $teacher->email,
            'password' => 'teachpass123',
        ])->assertRedirect(route('school.dashboard', ['school' => 'unity']));

        $this->actingAs($teacher)->get('http://unity.localhost/dashboard')
            ->assertOk()->assertSee('Unity');
    }

    public function test_non_member_cannot_sign_in_to_a_school(): void
    {
        $this->provisionSchool('closed', 'Closed');
        $stranger = User::factory()->create(['password' => bcrypt('strangerpass1')]);

        $this->from('http://closed.localhost/login')
            ->post('http://closed.localhost/login', ['email' => $stranger->email, 'password' => 'strangerpass1'])
            ->assertStatus(302)
            ->assertSessionHasErrors('email');
        $this->assertGuest(); // rejected: not a member of this school
    }

    public function test_school_admin_can_update_branding(): void
    {
        $admin = $this->superAdmin();
        $school = $this->provisionSchool('colorful', 'Colorful');
        $school->users()->syncWithoutDetaching([$admin->id => ['status' => 'active']]);

        $this->actingAs($admin)->post('http://colorful.localhost/settings/branding', [
            'brand_color' => '#12A97B',
            'welcome_headline' => 'Welcome to Colorful School',
            'welcome_message' => 'Please sign in below.',
        ])->assertRedirect();

        $school->refresh();
        $this->assertSame('#12A97B', $school->brand_color);
        $this->assertSame('Welcome to Colorful School', $school->welcome_headline);
        $this->get('http://colorful.localhost/')->assertSee('Welcome to Colorful School');
    }
}
