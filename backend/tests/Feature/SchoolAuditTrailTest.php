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
 * A school's own audit trail screen (2026-08-15). Shipped with a real
 * 500 on first deploy — $this->school()/authorizeAction() are defined
 * per controller in this codebase, not on the base Controller, and this
 * one assumed otherwise. This test exercises the real route end to end
 * so that class of mistake fails here instead of in production.
 */
class SchoolAuditTrailTest extends TestCase
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

    /** @return array{0: School, 1: User} */
    protected function schoolWithMember(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = app(Rbac::class)->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_an_admin_can_open_the_audit_trail_and_see_real_entries(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'audittrail1');

        app(TenantManager::class)->use($school);
        DB::connection('tenant')->table('audit_logs')->insert([
            'user_id' => $admin->id,
            'action' => 'student.updated',
            'auditable_type' => 'App\\Models\\Tenant\\Student',
            'auditable_id' => 7,
            'ip_address' => '102.89.1.5',
            'created_at' => now(),
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/audit-trail")
            ->assertOk()
            ->assertSee('Audit Trail')
            ->assertSee('student.updated')
            ->assertSee('102.89.1.5');
    }

    public function test_a_teacher_without_the_admin_permission_cannot_open_it(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'audittrail2');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/audit-trail")
            ->assertForbidden();
    }

    public function test_downloading_an_archive_that_does_not_exist_is_a_404_not_an_error(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'audittrail3');

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/audit-trail/archive/2026-01")
            ->assertNotFound();
    }
}
