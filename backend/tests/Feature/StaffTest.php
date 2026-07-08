<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Services\Bulk\Importer;
use App\Services\Bulk\Importers\StaffImporter;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'staff']));
        app(TenantManager::class)->use($school);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_staff_id_unique_and_full_name(): void
    {
        $staff = Staff::create(['staff_id' => 'STF/001', 'first_name' => 'Grace', 'other_names' => 'A', 'last_name' => 'Eze', 'position' => 'Accountant']);
        $this->assertSame('Grace A Eze', $staff->fullName());

        $this->expectException(\Illuminate\Database\QueryException::class);
        Staff::create(['staff_id' => 'STF/001', 'first_name' => 'Other', 'last_name' => 'Person']);
    }

    public function test_teaching_assignments(): void
    {
        $section = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $maths = Subject::create(['name' => 'Mathematics']);
        $staff = Staff::create(['staff_id' => 'STF/010', 'first_name' => 'Bola', 'last_name' => 'Ade']);

        $assignment = TeachingAssignment::create([
            'staff_id' => $staff->id, 'subject_id' => $maths->id, 'class_arm_id' => $classArm->id,
        ]);

        $this->assertTrue($staff->teachingAssignments->first()->is($assignment));
        $this->assertSame('Mathematics', $assignment->subject->name);
        $this->assertSame('JSS 1 A', $assignment->classArm->label());
    }

    public function test_class_teacher_reference_on_class_arm(): void
    {
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 1', 'sequence' => 1]);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $staff = Staff::create(['staff_id' => 'STF/020', 'first_name' => 'Ada', 'last_name' => 'Obi']);

        $classArm->update(['class_teacher_staff_id' => $staff->id]);

        $this->assertSame($staff->id, $classArm->fresh()->class_teacher_staff_id);
    }

    public function test_importer_template_and_bulk_import(): void
    {
        $this->assertSame(
            "staff_id,first_name,last_name,other_names,gender,email,phone,position\n",
            (new StaffImporter)->template()
        );

        Staff::create(['staff_id' => 'STF/EXIST', 'first_name' => 'X', 'last_name' => 'Y']);

        $rows = [
            ['staff_id' => 'STF/100', 'first_name' => 'Grace', 'last_name' => 'Eze', 'position' => 'Bursar'],
            ['staff_id' => 'STF/EXIST', 'first_name' => 'Dup', 'last_name' => 'Licate'],
            ['staff_id' => '', 'first_name' => 'No', 'last_name' => 'Id'],
        ];

        $result = (new StaffImporter)->import($rows, Importer::MODE_PARTIAL);

        $this->assertSame(1, $result->imported);
        $this->assertSame(2, $result->failed());
        $this->assertTrue(Staff::where('staff_id', 'STF/100')->exists());
    }
}
