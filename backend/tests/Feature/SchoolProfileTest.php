<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * School Profile (2026-07-17, Settings nav reorganization): a school's
 * own institutional details, previously nowhere editable (Branding only
 * ever covered the visual side, Campuses only ever covered locations).
 */
class SchoolProfileTest extends TestCase
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

    public function test_an_owner_can_update_the_school_profile(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'profileone');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/profile")->assertOk();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/profile", [
            'motto' => 'Excellence in all things',
            'email' => 'info@profileone.test',
            'phone' => '+2348000000000',
            'address' => '1 Test Street',
        ])->assertRedirect();

        $school->refresh();
        $this->assertSame('Excellence in all things', $school->motto);
        $this->assertSame('info@profileone.test', $school->email);
    }

    public function test_a_teacher_cannot_edit_the_school_profile(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'profiletwo');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/settings/profile")->assertForbidden();
    }
}
