<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Subject;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** ppms:setup-subjects: plans first, applies only with --apply, never deletes a subject. */
class PpmsSubjectSetupTest extends TestCase
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

    public function test_it_plans_first_then_applies_per_group_without_deleting_anything(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'ppmssubjects']));
        app(TenantManager::class)->use($school);
        $basicSection = Section::create(['name' => 'Basic', 'sequence' => 2]);
        $sssSection = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $basic = SchoolClass::create(['section_id' => $basicSection->id, 'name' => 'Basic 1', 'sequence' => 1]);
        $sss = SchoolClass::create(['section_id' => $sssSection->id, 'name' => 'SSS 1', 'sequence' => 2]);
        $other = SchoolClass::create(['section_id' => $sssSection->id, 'name' => 'Odd Class', 'sequence' => 3]);
        $jss = SchoolClass::create(['section_id' => $sssSection->id, 'name' => 'JSS 1', 'sequence' => 4]);
        $arm = ClassArm::create(['school_class_id' => $basic->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);

        $maths = Subject::create(['name' => 'MATHEMATICS', 'sequence' => 1]);
        $old = Subject::create(['name' => 'Old Subject', 'sequence' => 2]);
        $basic->subjects()->attach([$maths->id => ['is_mandatory' => true, 'is_examinable' => true], $old->id => ['is_mandatory' => true, 'is_examinable' => true]]);
        $other->subjects()->attach($old->id, ['is_mandatory' => true, 'is_examinable' => true]);
        app(TenantManager::class)->forget();

        // Plan only: nothing changes.
        $this->artisan('ppms:setup-subjects', ['--slug' => $school->slug])->assertExitCode(0);
        app(TenantManager::class)->use($school);
        $this->assertSame(2, Subject::count());
        $this->assertSame(2, $basic->subjects()->count());
        app(TenantManager::class)->forget();

        $this->artisan('ppms:setup-subjects', ['--slug' => $school->slug, '--apply' => true])->assertExitCode(0);

        app(TenantManager::class)->use($school);
        $names = $basic->subjects()->pluck('name')->all();
        $this->assertCount(16, $names);
        $this->assertContains('MATHEMATICS', $names);           // the existing subject is reused, not duplicated
        $this->assertSame(1, Subject::whereRaw('lower(name) = ?', ['mathematics'])->count());
        $this->assertNotContains('Old Subject', $names);       // detached from the class
        $this->assertNotNull(Subject::find($old->id));         // but never deleted
        $this->assertContains('ICT', $names);
        $this->assertContains('CRS', $names);
        $this->assertTrue((bool) $basic->subjects()->where('subjects.name', 'ICT')->first()->pivot->is_mandatory);

        // Basic subjects carry their report card group, in the sheet's order.
        $this->assertSame('Maths & Sciences', Subject::find($maths->id)->category);
        $english = Subject::where('name', 'English Language')->first();
        $phonics = Subject::where('name', 'Phonics & Verbal Reasoning')->first();
        $this->assertSame('Languages', $english->category);
        $this->assertLessThan($phonics->sequence, $english->sequence);

        // JSS starts English, Mathematics, Civic Education, Basic Technology; the rest follow in the list's order.
        $jssNames = $jss->subjects()->pluck('subjects.name')->all();
        $this->assertCount(14, $jssNames);
        $this->assertSame(['English Language', 'MATHEMATICS', 'Civic Education', 'Basic Technology'], array_slice($jssNames, 0, 4));
        $this->assertSame('Basic Science', $jssNames[4]);
        // ...while the same shared subjects sit in the Basic class's own order (no Civic/Technology first there).
        $this->assertSame('English Language', $basic->subjects()->first()->name);

        // SSS: English and Mathematics mandatory, the rest optional.
        $sssSubjects = $sss->subjects()->get();
        $this->assertCount(25, $sssSubjects);
        $this->assertTrue((bool) $sssSubjects->firstWhere('name', 'English Language')->pivot->is_mandatory);
        $this->assertFalse((bool) $sssSubjects->firstWhere('name', 'Biology')->pivot->is_mandatory);
        $this->assertNotNull($sssSubjects->firstWhere('name', 'Geography'));

        // A class matching no list is left completely alone.
        $this->assertSame([$old->id], $other->subjects()->pluck('subjects.id')->all());
        app(TenantManager::class)->forget();
    }
}
