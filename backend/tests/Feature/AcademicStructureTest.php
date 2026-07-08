<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\House;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AcademicStructureTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $school = app(TenantManager::class)->provision(
            School::factory()->create(['slug' => 'academic'])
        );
        app(TenantManager::class)->use($school);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_tenant_database_has_all_academic_tables(): void
    {
        foreach (['academic_sessions', 'terms', 'sections', 'school_classes', 'arms', 'class_arms', 'subjects', 'class_subject', 'houses'] as $table) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::connection('tenant')->hasTable($table),
                "missing tenant table {$table}"
            );
        }
    }

    public function test_session_terms_and_current_flags(): void
    {
        $session = AcademicSession::create(['name' => '2025/2026']);
        $session->makeCurrent();

        foreach (['First Term', 'Second Term', 'Third Term'] as $i => $name) {
            Term::create(['academic_session_id' => $session->id, 'name' => $name, 'sequence' => $i + 1]);
        }

        $this->assertCount(3, $session->terms);
        $this->assertTrue(AcademicSession::current()->first()->is($session));

        $second = Term::where('sequence', 2)->first();
        $second->makeCurrent();
        $this->assertSame(1, Term::where('is_current', true)->count());
        $this->assertTrue(Term::current()->first()->is($second));
    }

    public function test_section_class_arm_hierarchy_and_label(): void
    {
        $primary = Section::create(['name' => 'Primary', 'sequence' => 2]);
        $basic1 = SchoolClass::create(['section_id' => $primary->id, 'name' => 'Basic 1', 'sequence' => 1]);
        $armA = Arm::create(['name' => 'A']);
        $armB = Arm::create(['name' => 'B']);

        $ca1 = ClassArm::create(['school_class_id' => $basic1->id, 'arm_id' => $armA->id]);
        ClassArm::create(['school_class_id' => $basic1->id, 'arm_id' => $armB->id]);

        $this->assertTrue($basic1->section->is($primary));
        $this->assertCount(2, $basic1->arms);
        $this->assertSame('Basic 1 A', $ca1->fresh()->label());

        // single stream class: arm_id null
        $nursery = Section::create(['name' => 'Nursery', 'sequence' => 1]);
        $creche = SchoolClass::create(['section_id' => $nursery->id, 'name' => 'Creche', 'sequence' => 1]);
        $single = ClassArm::create(['school_class_id' => $creche->id, 'arm_id' => null]);
        $this->assertSame('Creche', $single->fresh()->label());
    }

    public function test_class_subject_pivot(): void
    {
        $section = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $jss1 = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $maths = Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);
        $english = Subject::create(['name' => 'English', 'code' => 'ENG']);

        $jss1->subjects()->sync([$maths->id, $english->id]);

        $this->assertCount(2, $jss1->subjects);
        $this->assertTrue($maths->classes->first()->is($jss1));
    }

    public function test_houses(): void
    {
        $red = House::create(['name' => 'Red House', 'color' => '#E14F63']);
        // houses is a tenant-only table, so assert against the tenant connection.
        $this->assertTrue(House::where('name', 'Red House')->exists());
        $this->assertSame('#E14F63', $red->fresh()->color);
    }

    public function test_academic_records_are_audited_in_tenant_db(): void
    {
        Section::create(['name' => 'Primary', 'sequence' => 1]);

        $this->assertSame(
            1,
            \App\Models\AuditLog::on('tenant')
                ->where('auditable_type', Section::class)
                ->where('action', 'created')
                ->count()
        );
    }
}
