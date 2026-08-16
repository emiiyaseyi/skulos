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
 * The Guided Setup wizard had no test coverage at all (found
 * 2026-08-15 while fixing its step indicator), despite being the first
 * screen a brand new school's owner is sent to — the one place where a
 * failure means a paying customer's very first impression is a broken
 * page. These are deliberately smoke-level: that every step renders,
 * and that it is not reachable by someone who should not see it.
 */
class SetupScreenTest extends TestCase
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
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $owner = User::factory()->create();
        $role = app(Rbac::class)->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $owner];
    }

    public function test_every_setup_step_renders_for_a_school_owner(): void
    {
        [$school, $owner] = $this->schoolWithOwner('setupsmoke');

        // Walks every step rather than just the first: each one renders
        // its own distinct branch of the template, so a broken branch
        // would otherwise only surface for whichever real school
        // happened to reach that step.
        for ($step = 1; $step <= 10; $step++) {
            $this->actingAs($owner)
                ->get(route('school.setup', ['school' => $school->slug, 'step' => $step]))
                ->assertOk();
        }
    }

    public function test_the_step_indicator_labels_cannot_overflow_their_pill(): void
    {
        [$school, $owner] = $this->schoolWithOwner('setuppill');

        $response = $this->actingAs($owner)
            ->get(route('school.setup', ['school' => $school->slug, 'step' => 1]));

        // min-width:0 is what actually stops a nowrap label spilling out
        // over the step number (a flex item will not shrink below its
        // content width without it), so it is asserted explicitly rather
        // than left to be silently dropped by a future style tidy-up.
        $response->assertOk()
            ->assertSee('text-overflow:ellipsis', false)
            ->assertSee('min-width:0', false);
    }

    public function test_a_school_can_create_its_own_house_names_instead_of_the_suggested_ones(): void
    {
        [$school, $owner] = $this->schoolWithOwner('setupown');

        $this->actingAs($owner)->post(route('school.setup.groups', ['school' => $school->slug]), [
            'kind' => 'houses',
            'selected' => ['Red House'],
            'custom' => ['Falcon House', '', 'Eagle House'],
        ])->assertRedirect();

        app(\App\Services\Tenancy\TenantManager::class)->use($school);
        $names = \App\Models\Tenant\House::pluck('name')->all();
        // Ticked suggestions and typed names both land, blanks ignored.
        $this->assertContains('Red House', $names);
        $this->assertContains('Falcon House', $names);
        $this->assertContains('Eagle House', $names);
        $this->assertCount(3, $names);
        app(\App\Services\Tenancy\TenantManager::class)->forget();
    }

    public function test_a_step_can_be_skipped_and_then_restored(): void
    {
        [$school, $owner] = $this->schoolWithOwner('setupskip');

        $this->actingAs($owner)->post(route('school.setup.skip', ['school' => $school->slug]), ['step' => 'clubs'])
            ->assertRedirect();

        app(\App\Services\Tenancy\TenantManager::class)->use($school);
        $this->assertContains('clubs', \App\Http\Controllers\School\SetupController::skippedSteps());
        app(\App\Services\Tenancy\TenantManager::class)->forget();

        // Pressing it again restores the step: skipping is never a
        // one-way door.
        $this->actingAs($owner)->post(route('school.setup.skip', ['school' => $school->slug]), ['step' => 'clubs']);

        app(\App\Services\Tenancy\TenantManager::class)->use($school);
        $this->assertNotContains('clubs', \App\Http\Controllers\School\SetupController::skippedSteps());
        app(\App\Services\Tenancy\TenantManager::class)->forget();
    }

    public function test_an_assessment_structure_over_100_marks_is_refused(): void
    {
        [$school, $owner] = $this->schoolWithOwner('setupmarks');

        // Saving this would silently break every percentage and grade
        // band the school ever computes, so it has to fail loudly.
        $this->actingAs($owner)->post(route('school.setup.assessment', ['school' => $school->slug]), [
            'components' => [
                ['name' => 'CA1', 'type' => 'ca', 'max_score' => 50],
                ['name' => 'CA2', 'type' => 'ca', 'max_score' => 50],
                ['name' => 'Exam', 'type' => 'exam', 'max_score' => 60],
            ],
        ])->assertSessionHas('error');

        app(\App\Services\Tenancy\TenantManager::class)->use($school);
        $this->assertSame(0, \App\Models\Tenant\AssessmentComponent::count());
        app(\App\Services\Tenancy\TenantManager::class)->forget();
    }

    public function test_the_student_sheet_always_includes_the_columns_the_import_needs(): void
    {
        [$school, $owner] = $this->schoolWithOwner('setupsheet');

        // Ask for one optional column only. The import cannot place a
        // student without admission number, both names and a class, so
        // those must appear regardless of what was ticked -- otherwise
        // the sheet gets filled in and only fails on upload.
        $response = $this->actingAs($owner)->get(
            route('school.setup.student-template', ['school' => $school->slug]).'?columns[]=address'
        );

        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';
        file_put_contents($path, $response->getContent());

        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $header = $book->getSheet(0)->toArray(null, true, true, false)[0];

        $this->assertContains('Admission Number', $header);
        $this->assertContains('First Name', $header);
        $this->assertContains('Last Name', $header);
        $this->assertContains('Class', $header);
        $this->assertContains('Address', $header);
        // Not ticked, so genuinely absent: the point is a shorter sheet.
        $this->assertNotContains('State of Origin', $header);

        // The class column has to be pickable, which is the whole
        // reason this is a workbook and not a CSV.
        $this->assertNotNull($book->getSheetByName('Classes'), 'No class list sheet for the dropdown to read.');

        @unlink($path);
    }

    public function test_a_non_member_cannot_reach_another_schools_setup(): void
    {
        [$school] = $this->schoolWithOwner('setupowned');
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)
            ->get(route('school.setup', ['school' => $school->slug, 'step' => 1]));

        $this->assertContains($response->getStatusCode(), [403, 404, 302]);
    }
}
