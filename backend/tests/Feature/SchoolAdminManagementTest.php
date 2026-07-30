<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\GradingBand;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Subject;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Platform owner managing a school's own administrator accounts (user
 * request 2026-07-09): reset a school admin's password, add another
 * admin, or revoke one admin's access without touching the school itself.
 */
class SchoolAdminManagementTest extends TestCase
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

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    protected function schoolWithOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $owner = User::factory()->create(['email' => "owner@{$slug}.test", 'password' => bcrypt('original-pass')]);
        // Cloned into this school, not the shared template row (2026-07-16
        // fix, this fixture was modeling the exact bug it should have
        // been catching): SchoolAdminController::show() now correctly
        // queries admins by a school-scoped role id, a template-role
        // member would silently vanish from that list.
        $ownerRole = app(\App\Services\Rbac\Rbac::class)->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        return [$school, $owner];
    }

    /**
     * Audit follow-up 2026-07-30: the Schools index used to load every
     * provisioned school with no bound at all. Proves the fix actually
     * paginates (not just "still renders") and that the stat tiles stay
     * accurate platform-wide totals, not just a count of the page shown.
     */
    public function test_the_schools_index_paginates_and_stat_tiles_stay_platform_wide(): void
    {
        $platformAdmin = $this->superAdmin();
        School::factory()->count(35)->create(['status' => School::STATUS_ACTIVE]);

        $response = $this->actingAs($platformAdmin)->get(route('admin.schools.index'));
        $response->assertOk();

        $schools = $response->viewData('schools');
        $this->assertSame(30, $schools->perPage());
        $this->assertSame(30, $schools->count(), 'only one page worth of rows should render');
        $this->assertGreaterThan(30, $schools->total(), 'the paginator must still know the real total');
        $this->assertSame(35, $response->viewData('stats')['schools'], 'the stat tile must count every school, not just the page shown');
    }

    /**
     * Regression test for a live bug found 2026-07-12: creating a school
     * with a manually typed, mixed-case slug ("Training") stored it
     * verbatim, which 404'd on its own subdomain (ResolveTenant's lookup
     * found nothing, since a browser always sends a lowercase host) and
     * corrupted its tenant database name (TenantManager::databaseNameFor()
     * strips anything outside [a-z0-9_] without lowercasing first, so the
     * "T" was silently deleted, producing a database named "raining"
     * instead of "training"). The slug must always come out lowercased,
     * whether typed by the admin or auto derived from the school name.
     */
    public function test_creating_a_school_with_a_mixed_case_slug_stores_it_lowercased(): void
    {
        $platformAdmin = $this->superAdmin();

        $this->actingAs($platformAdmin)->post(route('admin.schools.store'), [
            'name' => 'Training',
            'slug' => 'Training',
            'admin_name' => 'School Admin',
            'admin_email' => 'admin@training.test',
            'admin_password' => 'password123',
        ])->assertRedirect(route('admin.schools.index'));

        $school = School::where('name', 'Training')->firstOrFail();
        $this->assertSame('training', $school->slug);
        $this->assertSame('training', $school->database);
        $this->assertTrue($school->isProvisioned());
    }

    /**
     * Audit finding L-2 (2026-07-17): provision() already marks a school
     * active/reachable at its subdomain the moment it returns, before
     * attachAdmin() runs. Simulates that second step failing (here, by
     * removing the 'school-owner' template role attachAdmin() clones
     * from, so Rbac::cloneTemplateRole()'s firstOrFail() throws) and
     * proves the school is suspended rather than left silently active
     * with no owner able to sign in.
     */
    public function test_a_school_is_suspended_not_left_ownerless_if_attaching_the_admin_fails(): void
    {
        $platformAdmin = $this->superAdmin();
        Role::whereNull('school_id')->where('slug', 'school-owner')->delete();

        $this->actingAs($platformAdmin)->post(route('admin.schools.store'), [
            'name' => 'Broken Provision', 'slug' => 'brokenprov',
            'admin_name' => 'School Admin', 'admin_email' => 'admin@brokenprov.test', 'admin_password' => 'password123',
        ])->assertRedirect();

        $school = School::where('slug', 'brokenprov')->firstOrFail();
        $this->assertTrue($school->isProvisioned()); // the tenant DB itself was created fine
        $this->assertSame(School::STATUS_SUSPENDED, $school->status);
        $this->assertSame(School::SUSPENSION_REASON_PROVISIONING_FAILED, $school->suspension_reason);
        // The User row itself was created before the failing step
        // (Rbac::cloneTemplateRole()), but never attached to the school
        // with a role, so nobody can sign in and reach it.
        $this->assertSame(0, $school->users()->count());
    }

    /** Same normalization must apply when no slug is typed at all, deriving one from the name. */
    public function test_creating_a_school_without_a_slug_derives_a_lowercase_one(): void
    {
        $platformAdmin = $this->superAdmin();

        $this->actingAs($platformAdmin)->post(route('admin.schools.store'), [
            'name' => 'Bright Future Academy',
            'admin_name' => 'School Admin',
            'admin_email' => 'admin@bfa.test',
            'admin_password' => 'password123',
        ])->assertRedirect(route('admin.schools.index'));

        $school = School::where('name', 'Bright Future Academy')->firstOrFail();
        $this->assertSame('bright-future-academy', $school->slug);
    }

    public function test_platform_admin_can_reset_a_school_admins_password(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        [$school, $owner] = $this->schoolWithOwner('resetpw');
        $platformAdmin = $this->superAdmin();

        $response = $this->actingAs($platformAdmin)
            ->post(route('admin.schools.admins.reset-password', ['school' => $school->id, 'user' => $owner->id]));

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertStringContainsString($owner->email, session('status'));

        $owner->refresh();
        $this->assertFalse(Hash::check('original-pass', $owner->password));

        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\PasswordResetByAdminMail::class,
            fn ($mail) => $mail->hasTo($owner->email) && $mail->user->is($owner)
        );
    }

    public function test_platform_admin_can_add_another_school_administrator(): void
    {
        [$school] = $this->schoolWithOwner('addadmin');
        $platformAdmin = $this->superAdmin();

        $this->actingAs($platformAdmin)->post(route('admin.schools.admins.store', ['school' => $school->id]), [
            'name' => 'Second Admin',
            'email' => 'second@addadmin.test',
            'password' => 'secondpass123',
        ])->assertRedirect();

        $newAdmin = User::where('email', 'second@addadmin.test')->first();
        $this->assertNotNull($newAdmin);
        $this->assertTrue($newAdmin->isMemberOf($school));

        // Sign out the platform admin first, or the "guest" middleware on
        // /login bounces the request away before it is even processed.
        auth()->logout();
        $this->post("http://{$school->slug}.localhost/login", [
            'email' => 'second@addadmin.test',
            'password' => 'secondpass123',
        ])->assertRedirect(route('school.dashboard', ['school' => $school->slug]));
    }

    public function test_platform_admin_can_revoke_and_restore_an_admins_access(): void
    {
        [$school, $owner] = $this->schoolWithOwner('revokeaccess');
        $platformAdmin = $this->superAdmin();

        $this->actingAs($platformAdmin)
            ->post(route('admin.schools.admins.toggle-access', ['school' => $school->id, 'user' => $owner->id]))
            ->assertRedirect();

        $this->assertFalse($owner->fresh()->isMemberOf($school));

        auth()->logout();
        $this->post("http://{$school->slug}.localhost/login", [
            'email' => $owner->email,
            'password' => 'original-pass',
        ])->assertSessionHasErrors('email');

        $this->actingAs($platformAdmin)
            ->post(route('admin.schools.admins.toggle-access', ['school' => $school->id, 'user' => $owner->id]))
            ->assertRedirect();

        $this->assertTrue($owner->fresh()->isMemberOf($school));
    }

    public function test_platform_admin_can_clone_a_schools_structure_onto_a_new_school(): void
    {
        [$source] = $this->schoolWithOwner('sourceschool');
        $platformAdmin = $this->superAdmin();

        app(TenantManager::class)->use($source);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'SS 1', 'sequence' => 1]);
        $subject = Subject::create(['name' => 'Chemistry']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $scale = GradingScale::create(['name' => 'Custom Scale', 'is_default' => true]);
        GradingBand::create(['grading_scale_id' => $scale->id, 'min_score' => 0, 'max_score' => 100, 'grade' => 'A', 'remark' => 'Good']);
        app(TenantManager::class)->forget();

        $this->actingAs($platformAdmin)->post(route('admin.schools.clone', ['school' => $source->id]), [
            'name' => 'Cloned School',
            'admin_name' => 'Clone Admin',
            'admin_email' => 'cloneadmin@example.com',
            'admin_password' => 'clonepass123',
        ])->assertRedirect(route('admin.schools.index'));

        $clone = School::where('name', 'Cloned School')->first();
        $this->assertNotNull($clone);
        $this->assertTrue($clone->isProvisioned());

        app(TenantManager::class)->use($clone);
        $clonedSection = Section::where('name', 'Secondary')->first();
        $clonedClass = SchoolClass::where('name', 'SS 1')->first();
        $clonedSubject = Subject::where('name', 'Chemistry')->first();
        $clonedScale = GradingScale::where('name', 'Custom Scale')->first();
        $offeredSubjectsCount = $clonedClass?->subjects()->count();
        app(TenantManager::class)->forget();

        $this->assertNotNull($clonedSection);
        $this->assertNotNull($clonedClass);
        $this->assertSame($clonedSection->id, $clonedClass->section_id);
        $this->assertNotNull($clonedSubject);
        $this->assertNotNull($clonedScale);
        $this->assertSame(1, $offeredSubjectsCount);

        // No student/staff data is copied; only the new admin is a member.
        $this->assertCount(1, $clone->users);
        $this->assertSame('cloneadmin@example.com', $clone->users->first()->email);
    }

    /**
     * Impersonation is a two-hop, signed-URL handoff, not a direct cross
     * host redirect (2026-07-21 fix: this app runs with SESSION_DOMAIN
     * unset, host-only cookies, so a cookie set on the admin's bare
     * domain is never sent to a school's subdomain and vice versa — a
     * direct redirect silently lost the session, found live as "View as
     * this admin" landing on a fully signed out portal). Each direction
     * ends with a redirect to a signed URL on the OTHER host, which is
     * where the actual login for that host happens.
     */
    public function test_platform_admin_can_impersonate_a_school_admin_for_support_and_return(): void
    {
        [$school, $owner] = $this->schoolWithOwner('impersonate');
        $platformAdmin = $this->superAdmin();

        $start = $this->actingAs($platformAdmin)
            ->post(route('admin.schools.admins.impersonate', ['school' => $school->id, 'user' => $owner->id]));

        $start->assertRedirect();
        $handoffUrl = $start->headers->get('Location');
        $this->assertStringContainsString('/impersonate/consume/', $handoffUrl);

        $this->get($handoffUrl)
            ->assertRedirect(route('school.dashboard', ['school' => $school->slug]));
        $this->assertAuthenticatedAs($owner);
        $this->assertSame($platformAdmin->id, session('impersonator_id'));

        $this->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('You are viewing as');

        $end = $this->post("http://{$school->slug}.localhost/session/end-impersonation");
        $end->assertRedirect();
        $resumeUrl = $end->headers->get('Location');
        $this->assertStringContainsString('/session/resume-admin/', $resumeUrl);

        $this->get($resumeUrl)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($platformAdmin);
    }

    public function test_impersonation_is_recorded_in_the_audit_trail(): void
    {
        [$school, $owner] = $this->schoolWithOwner('auditimp');
        $platformAdmin = $this->superAdmin();

        $start = $this->actingAs($platformAdmin)
            ->post(route('admin.schools.admins.impersonate', ['school' => $school->id, 'user' => $owner->id]));
        $this->get($start->headers->get('Location'));

        $end = $this->post("http://{$school->slug}.localhost/session/end-impersonation");
        $this->get($end->headers->get('Location'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'support.impersonate.start', 'auditable_id' => $owner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.impersonate.end', 'auditable_id' => $owner->id]);
    }

    public function test_the_signed_consume_url_cannot_be_reused_or_tampered_with(): void
    {
        [$school, $owner] = $this->schoolWithOwner('impersonatetamper');
        $platformAdmin = $this->superAdmin();

        $start = $this->actingAs($platformAdmin)
            ->post(route('admin.schools.admins.impersonate', ['school' => $school->id, 'user' => $owner->id]));
        $handoffUrl = $start->headers->get('Location');

        // Tampering with the target user id must invalidate the signature.
        $tampered = preg_replace('/consume\/\d+/', 'consume/999999', $handoffUrl);
        $this->get($tampered)->assertForbidden();

        // The real, untampered URL still works once.
        $this->get($handoffUrl)->assertRedirect(route('school.dashboard', ['school' => $school->slug]));
    }

    public function test_only_platform_super_admin_can_manage_school_administrators(): void
    {
        [$school, $owner] = $this->schoolWithOwner('notallowed');
        $regularUser = User::factory()->create();

        $this->actingAs($regularUser)
            ->post(route('admin.schools.admins.reset-password', ['school' => $school->id, 'user' => $owner->id]))
            ->assertNotFound();
    }
}
