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
        $ownerRole = Role::whereNull('school_id')->where('slug', 'school-owner')->first();
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        return [$school, $owner];
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

    public function test_platform_admin_can_impersonate_a_school_admin_for_support_and_return(): void
    {
        [$school, $owner] = $this->schoolWithOwner('impersonate');
        $platformAdmin = $this->superAdmin();

        $response = $this->actingAs($platformAdmin)
            ->post(route('admin.schools.admins.impersonate', ['school' => $school->id, 'user' => $owner->id]));

        $response->assertRedirect(route('school.dashboard', ['school' => $school->slug]));
        $this->assertAuthenticatedAs($owner);
        $this->assertSame($platformAdmin->id, session('impersonator_id'));

        $this->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('You are viewing as');

        $this->post(route('session.end-impersonation'))->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($platformAdmin);
        $this->assertNull(session('impersonator_id'));
    }

    public function test_impersonation_is_recorded_in_the_audit_trail(): void
    {
        [$school, $owner] = $this->schoolWithOwner('auditimp');
        $platformAdmin = $this->superAdmin();

        $this->actingAs($platformAdmin)
            ->post(route('admin.schools.admins.impersonate', ['school' => $school->id, 'user' => $owner->id]));
        $this->post(route('session.end-impersonation'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'support.impersonate.start', 'auditable_id' => $owner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.impersonate.end', 'auditable_id' => $owner->id]);
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
