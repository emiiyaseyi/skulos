<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Plan;
use App\Models\School;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * School Health & Storage (Dbase recommendation: Maintenance Center +
 * Storage Dashboard + Health Engine, user request 2026-07-12).
 */
class MaintenanceCenterTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        $this->seed(RbacSeeder::class);
        $this->rbac = app(Rbac::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithMember(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_the_page_shows_real_storage_and_health_data(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'maintone');

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/maintenance");

        $response->assertOk()
            ->assertSee('Health score')
            ->assertSee('Students & Guardians')
            ->assertSee('Optimize database now')
            ->assertSee('No backup has ever been taken');
    }

    public function test_a_student_with_no_class_and_a_missing_term_are_real_findings(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'mainttwo');

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/maintenance");

        $response->assertOk()
            ->assertSee('No current academic term is set')
            ->assertSee('1 active student(s) have no class assigned');
    }

    public function test_a_recent_backup_removes_the_backup_finding(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'maintthree');

        Backup::create([
            'school_id' => $school->id, 'disk' => 'local', 'file_path' => 'x/y.sqlite',
            'size_bytes' => 2048, 'type' => Backup::TYPE_MANUAL,
        ]);

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/maintenance");

        $response->assertOk()->assertDontSee('No backup has ever been taken');
    }

    public function test_storage_limit_from_the_schools_plan_shows_a_usage_bar(): void
    {
        $plan = Plan::create([
            'name' => 'Foundation', 'slug' => 'foundation-test', 'price_per_student_per_term' => 500,
            'storage_limit_mb' => 500, 'modules' => [], 'features' => [],
        ]);
        [$school, $owner] = $this->schoolWithMember('school-owner', 'maintfour');
        $school->update(['plan_id' => $plan->id]);

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/maintenance");

        $response->assertOk()->assertSee('of 500 MB plan allowance');
    }

    public function test_owner_can_optimize_the_database_on_demand(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'maintfive');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/maintenance/optimize")
            ->assertRedirect()->assertSessionHas('status');
    }

    public function test_owner_can_run_retention_cleanup_on_demand(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'maintsix');

        app(TenantManager::class)->use($school);
        $user = User::factory()->create();
        Notification::create(['user_id' => $user->id, 'title' => 'Old', 'body' => 'x', 'category' => 'x', 'read_at' => now()->subDays(200)]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/maintenance/retention")
            ->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $remaining = Notification::count();
        app(TenantManager::class)->forget();
        $this->assertSame(0, $remaining);
    }

    public function test_a_teacher_without_school_info_manage_settings_cannot_run_maintenance_actions(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'maintseven');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/maintenance/optimize")
            ->assertForbidden();
    }
}
