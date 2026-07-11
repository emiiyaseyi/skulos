<?php

namespace Tests\Unit;

use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every class picker (Results entry, the Student form) used to sort by
 * ClassArm::label() alphabetically, putting "JSS 3" before "Nursery 1"
 * before "Primary 1" (found 2026-07-11 from a user screenshot). orderKey()
 * fixes it by sorting on (section.sequence, class.sequence) instead.
 */
class ClassArmOrderKeyTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_class_arms_sort_by_section_and_class_sequence_not_alphabetically(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'orderone']));
        app(TenantManager::class)->use($school);

        $nursery = Section::create(['name' => 'Nursery', 'sequence' => 1]);
        $primary = Section::create(['name' => 'Primary', 'sequence' => 2]);
        $secondary = Section::create(['name' => 'Secondary', 'sequence' => 3]);

        $jss3 = SchoolClass::create(['section_id' => $secondary->id, 'name' => 'JSS 3', 'sequence' => 3]);
        $nursery1 = SchoolClass::create(['section_id' => $nursery->id, 'name' => 'Nursery 1', 'sequence' => 1]);
        $primary1 = SchoolClass::create(['section_id' => $primary->id, 'name' => 'Primary 1', 'sequence' => 1]);

        ClassArm::create(['school_class_id' => $jss3->id, 'arm_id' => null]);
        ClassArm::create(['school_class_id' => $nursery1->id, 'arm_id' => null]);
        ClassArm::create(['school_class_id' => $primary1->id, 'arm_id' => null]);
        $arms = ClassArm::with('schoolClass.section')->get();

        $sorted = $arms->sortBy(fn (ClassArm $a) => $a->orderKey())->map(fn (ClassArm $a) => $a->label())->values()->all();

        $this->assertSame(['Nursery 1', 'Primary 1', 'JSS 3'], $sorted, 'must follow section sequence, not alphabetical label order');

        app(TenantManager::class)->forget();
    }
}
