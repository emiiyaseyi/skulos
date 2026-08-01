<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SKULOS Assessment Hub, Phase 2h: the new top-level "Learning
 * Management" nav group, gated purely on Rbac::check() (which already
 * consults Rbac::moduleState() internally) — same pattern already
 * proven by the pre-existing Assignments nav entry, not tied to any
 * Plan's feature catalog.
 */
class CbtNavTest extends TestCase
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

    public function test_an_owner_sees_the_full_learning_management_group_once_the_module_is_enabled(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'navone']));
        $this->rbac->setModuleState($school, 'assessment_hub', Module::STATE_ENABLED);

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk()
            ->assertSee('Learning Management')
            ->assertSee('Question Bank')
            ->assertSee('Proctor / Invigilator View')
            ->assertSee('Incidents');
    }

    public function test_a_student_only_sees_take_assessments_not_the_admin_entries(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'navtwo']));
        $this->rbac->setModuleState($school, 'assessment_hub', Module::STATE_ENABLED);

        $student = User::factory()->create();
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        $response = $this->actingAs($student)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk()
            ->assertSee('Take Assessments')
            ->assertDontSee('Question Bank')
            ->assertDontSee('Proctor / Invigilator View');
    }

    public function test_the_whole_group_is_hidden_when_the_module_is_disabled(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'navthree']));
        // No setModuleState() call at all — a fresh school has no plan
        // assigned and assessment_hub isn't in any real Plan's modules
        // array, so it stays disabled by default, same as Admissions'
        // own precedent.

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk()->assertDontSee('Learning Management');
    }
}
