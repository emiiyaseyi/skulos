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

        $this->actingAs($this->superAdmin())->get('http://localhost/admin')->assertOk()->assertSee('Platform Dashboard');
    }

    public function test_super_admin_can_create_a_school_with_its_own_admin(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post('http://localhost/admin/schools', [
            'name' => 'Greenfield Academy',
            'slug' => 'greenfield',
            'admin_name' => 'Jane Head',
            'admin_email' => 'jane@greenfield.test',
            'admin_password' => 'setup-pass-123',
        ])->assertRedirect(route('admin.schools.index'));

        $school = School::where('slug', 'greenfield')->first();
        $this->assertNotNull($school);
        $this->assertTrue($school->isProvisioned());
        $this->assertSame(School::STATUS_ACTIVE, $school->status);

        // The school administrator was created and attached (not the super admin).
        $schoolAdmin = User::where('email', 'jane@greenfield.test')->first();
        $this->assertNotNull($schoolAdmin);
        $this->assertFalse($schoolAdmin->is_super_admin);
        $this->assertTrue($schoolAdmin->isMemberOf($school));
    }

    public function test_creating_a_school_requires_admin_login_details(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('http://localhost/admin/schools', ['name' => 'No Admin School'])
            ->assertSessionHasErrors(['admin_name', 'admin_email', 'admin_password']);
    }

    public function test_the_created_school_admin_can_sign_in(): void
    {
        $this->actingAs($this->superAdmin())->post('http://localhost/admin/schools', [
            'name' => 'Sunrise', 'slug' => 'sunrise',
            'admin_name' => 'Sam Admin', 'admin_email' => 'sam@sunrise.test', 'admin_password' => 'sunrise-pass-1',
        ]);
        $this->app['auth']->forgetGuards(); // drop the super-admin session

        $this->post('http://sunrise.localhost/login', [
            'email' => 'sam@sunrise.test', 'password' => 'sunrise-pass-1',
        ])->assertRedirect(route('school.dashboard', ['school' => 'sunrise']));
        $this->assertAuthenticated();
    }

    public function test_school_welcome_page_renders_branding(): void
    {
        // The root URL is a guest redirect straight to the sign in page
        // (no separate low content "click here" page in between), which
        // renders the school's branding.
        $school = $this->provisionSchool('bright', 'Bright Stars');
        $school->update(['welcome_headline' => 'Hello Bright Stars', 'brand_color' => '#1B87D6']);

        $this->get('http://bright.localhost/')->assertRedirect('http://bright.localhost/login');
        $this->get('http://bright.localhost/login')
            ->assertOk()->assertSee('Hello Bright Stars')->assertSee('#1B87D6');
    }

    public function test_school_login_page_renders(): void
    {
        $school = $this->provisionSchool('portal', 'Portal School');

        $this->get('http://portal.localhost/login')
            ->assertOk()
            ->assertSee('Portal School')
            ->assertSee('Sign in');
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

    public function test_error_pages_use_the_branded_template_not_the_framework_default(): void
    {
        $school = $this->provisionSchool('errorpage', 'Error Page School');
        $school->update(['status' => \App\Models\School::STATUS_SUSPENDED]);

        $this->get('http://errorpage.localhost/login')
            ->assertForbidden()
            ->assertSee('SK', false)
            ->assertSee('This school is currently suspended', false)
            ->assertDontSee('Whoops');
    }

    public function test_idle_timeout_modal_and_keep_alive_route_are_wired_up(): void
    {
        $school = $this->provisionSchool('idlecheck', 'Idle Check');
        $teacher = User::factory()->create();
        $school->users()->attach($teacher->id, ['status' => 'active']);

        $this->actingAs($teacher)->get('http://idlecheck.localhost/dashboard')
            ->assertOk()
            ->assertSee('idleScrim', false)
            ->assertSee((string) config('skulos.session_timeout.idle_seconds'), false);

        $this->actingAs($teacher)->post(route('session.keep-alive'))
            ->assertOk()->assertJson(['ok' => true]);
    }

    public function test_keep_alive_requires_authentication(): void
    {
        $this->post(route('session.keep-alive'))->assertRedirect('/login');
    }

    public function test_member_of_one_school_cannot_view_another_schools_dashboard(): void
    {
        // Sessions are shared across subdomains, so this guard is what stops
        // a signed in user of school A from reading school B's data.
        $this->provisionSchool('alpha', 'Alpha School');
        $beta = $this->provisionSchool('beta', 'Beta School');

        $betaMember = User::factory()->create();
        $beta->users()->attach($betaMember->id, ['status' => 'active']);

        // Beta member reaches Beta.
        $this->actingAs($betaMember)->get('http://beta.localhost/dashboard')->assertOk();

        // But is bounced from Alpha (not a member) and logged out.
        $this->actingAs($betaMember)->get('http://alpha.localhost/dashboard')
            ->assertRedirect(route('school.login', ['school' => 'alpha']));
    }

    public function test_super_admin_can_view_any_school_dashboard(): void
    {
        $this->provisionSchool('omega', 'Omega School');
        $this->actingAs($this->superAdmin())->get('http://omega.localhost/dashboard')->assertOk();
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

    public function test_super_admin_can_suspend_resume_archive_and_delete_a_school(): void
    {
        $admin = $this->superAdmin();
        $school = $this->provisionSchool('lifecycle', 'Lifecycle School');
        $member = User::factory()->create();
        $school->users()->attach($member->id, ['status' => 'active']);

        // suspend -> members blocked
        $this->actingAs($admin)->post("http://localhost/admin/schools/{$school->id}/suspend")->assertRedirect();
        $this->assertSame(School::STATUS_SUSPENDED, $school->fresh()->status);
        $this->actingAs($member)->get('http://lifecycle.localhost/dashboard')->assertForbidden();

        // resume -> active again
        $this->actingAs($admin)->post("http://localhost/admin/schools/{$school->id}/activate")->assertRedirect();
        $this->assertSame(School::STATUS_ACTIVE, $school->fresh()->status);
        $this->actingAs($member)->get('http://lifecycle.localhost/dashboard')->assertOk();

        // archive -> blocked
        $this->actingAs($admin)->post("http://localhost/admin/schools/{$school->id}/archive")->assertRedirect();
        $this->assertSame(School::STATUS_ARCHIVED, $school->fresh()->status);
        $this->actingAs($member)->get('http://lifecycle.localhost/dashboard')->assertForbidden();

        // delete (soft) -> gone from platform, subdomain 404s
        $this->actingAs($admin)->delete("http://localhost/admin/schools/{$school->id}")->assertRedirect();
        $this->assertSoftDeleted('schools', ['id' => $school->id]);
        $this->get('http://lifecycle.localhost/')->assertNotFound();
    }

    public function test_a_deleted_school_appears_in_the_archive_and_can_be_restored(): void
    {
        $admin = $this->superAdmin();
        $school = $this->provisionSchool('archivable', 'Archivable School');

        $this->actingAs($admin)->delete("http://localhost/admin/schools/{$school->id}")->assertRedirect();

        $this->actingAs($admin)->get('http://localhost/admin/schools/archive')
            ->assertOk()->assertSee('Archivable School');

        $this->actingAs($admin)->post("http://localhost/admin/schools/{$school->id}/restore")->assertRedirect();

        $this->assertNull(School::find($school->id)?->deleted_at);
        $this->assertDatabaseHas('schools', ['id' => $school->id, 'deleted_at' => null]);
        $this->assertSame(0, School::onlyTrashed()->count());
    }

    public function test_lifecycle_actions_require_super_admin(): void
    {
        $school = $this->provisionSchool('guarded', 'Guarded');
        $member = User::factory()->create();

        $this->actingAs($member)->post("http://localhost/admin/schools/{$school->id}/suspend")->assertNotFound();
    }

    public function test_school_login_uses_the_chosen_design(): void
    {
        $school = $this->provisionSchool('designed', 'Designed School');
        $school->update(['login_layout' => 'split', 'welcome_headline' => 'Hello Designed']);

        // split design renders the branded panel markup
        $this->get('http://designed.localhost/login')
            ->assertOk()
            ->assertSee('lp-split', false)
            ->assertSee('Hello Designed');

        $school->update(['login_layout' => 'cover']);
        $this->get('http://designed.localhost/login')->assertOk()->assertSee('lp-cover', false);
    }

    public function test_admin_can_change_login_design(): void
    {
        $admin = $this->superAdmin();
        $school = $this->provisionSchool('pick', 'Pick School');
        $school->users()->syncWithoutDetaching([$admin->id => ['status' => 'active']]);

        $this->actingAs($admin)->post('http://pick.localhost/settings/branding', [
            'login_layout' => 'showcase',
        ])->assertRedirect();

        $this->assertSame('showcase', $school->fresh()->login_layout);
    }

    public function test_admin_can_choose_which_side_the_sign_in_box_appears_on(): void
    {
        $admin = $this->superAdmin();
        $school = $this->provisionSchool('boxside', 'Box Side School');
        $school->users()->syncWithoutDetaching([$admin->id => ['status' => 'active']]);

        $this->assertSame('right', $school->branding()['login_box_side']);

        $this->actingAs($admin)->post('http://boxside.localhost/settings/branding', [
            'login_layout' => 'split',
            'login_box_side' => 'left',
        ])->assertRedirect();

        $school->refresh();
        $this->assertSame('left', $school->login_box_side);

        // The "guest" middleware bounces an already signed in visitor away
        // from /login, so sign out first to see the page as a visitor would.
        $this->post('http://boxside.localhost/logout');
        $this->get('http://boxside.localhost/login')->assertOk()->assertSee('box-left', false);
    }

    public function test_root_url_sends_guests_to_login_and_members_to_dashboard(): void
    {
        $school = $this->provisionSchool('rooturl', 'Root URL School');
        $member = User::factory()->create();
        $school->users()->attach($member->id, ['status' => 'active']);

        $this->get('http://rooturl.localhost/')->assertRedirect('http://rooturl.localhost/login');
        $this->actingAs($member)->get('http://rooturl.localhost/')
            ->assertRedirect(route('school.dashboard', ['school' => 'rooturl']));
    }

    // The graceful-419 handler in bootstrap/app.php (a stale/expired CSRF
    // token redirects back with a friendly message instead of Laravel's
    // raw 419 page) is not covered by a feature test here: Laravel's
    // VerifyCsrfToken middleware short circuits and skips verification
    // entirely whenever runningUnitTests() is true, so a real
    // TokenMismatchException cannot be triggered through the HTTP test
    // client. Verified manually against the local dev server instead
    // (a wrong _token now 302s back with session('error') set, where it
    // previously rendered a bare 419 page).

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
    }

    /**
     * The brand colour field is a swatch grid plus a native colour picker
     * fallback, not just a bare text box (user request 2026-07-11: "swatch
     * grid of basic colours plus a native hex/drag colour picker
     * fallback"). Both controls sync into the same submitted hex field.
     */
    public function test_branding_page_renders_the_colour_swatch_grid(): void
    {
        $admin = $this->superAdmin();
        $school = $this->provisionSchool('swatchy', 'Swatchy');
        $school->users()->syncWithoutDetaching([$admin->id => ['status' => 'active']]);

        $this->actingAs($admin)->get('http://swatchy.localhost/settings/branding')
            ->assertOk()
            ->assertSee('color-swatch', false)
            ->assertSee('type="color"', false);
    }
}
