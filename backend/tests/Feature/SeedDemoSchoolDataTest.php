<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Demo/stress test data seeding (user request 2026-07-11: "seeded data
 * (admin, staff, students, parents) to all the schools... so that each
 * class has a minimum of 20 pupils per class, there will be class
 * teachers, subject teachers, Principal etc... covered from nursery to
 * secondary"). Re-running must top up, not duplicate, and generated
 * contact info must read as real (correctly cased names, real free email
 * providers, real Nigerian phone prefixes), not an obviously synthetic
 * tag, per the same day follow up "the seeded data must have real name
 * and info."
 */
class SeedDemoSchoolDataTest extends TestCase
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

    public function test_it_covers_every_class_from_nursery_to_secondary_with_the_minimum_headcount(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'seedone']));

        $this->artisan('schools:seed-demo-data', ['school' => 'seedone', '--per-class' => 5])
            ->assertSuccessful();

        app(TenantManager::class)->use($school);
        $arms = ClassArm::with('schoolClass.section')->get();
        $this->assertSame(15, $arms->count(), 'nursery (3) + primary (6) + jss (3) + ss (3) = 15 class arms');

        foreach ($arms as $arm) {
            $count = Student::where('current_class_arm_id', $arm->id)->count();
            $this->assertGreaterThanOrEqual(5, $count, "{$arm->label()} must have at least the requested minimum");
            $this->assertNotNull($arm->class_teacher_staff_id, "{$arm->label()} must have a class teacher");
            $this->assertNotNull($arm->assistant_teacher_staff_id, "{$arm->label()} must have a class assistant");
        }

        // Exactly one of each unique role, and exactly 5 subject teachers,
        // one per level band (user request 2026-07-12: "1 school owner per
        // school, same for all unique roles... for subject teachers: 1 in
        // nursery level, 1 for pry 1-3, 1 for pry4-6, 1 for jss 1-3, 1 for
        // sss 1-3"), not the old one-teacher-per-subject-per-section model.
        foreach (['Principal', 'Head Teacher', 'Registrar', 'Examination Officer', 'ICT Officer', 'Bursar'] as $position) {
            $this->assertSame(1, Staff::where('position', $position)->count(), "there must be exactly one {$position}");
        }
        $this->assertSame(5, Staff::where('position', 'like', 'Subject Teacher (%')->count(), 'exactly one subject teacher per level band');
        $this->assertSame(Student::count(), StudentGuardian::count(), 'every student gets exactly one guardian');
        app(TenantManager::class)->forget();
    }

    public function test_generated_contact_info_looks_real_not_synthetic(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'seedtwo']));

        $this->artisan('schools:seed-demo-data', ['school' => 'seedtwo', '--per-class' => 2])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $principal = Staff::where('position', 'Principal')->first();
        app(TenantManager::class)->forget();

        // A correctly cased first/last name turned into a lowercase
        // "first.last@realprovider.com" address, not a uniqid-tagged one.
        $expectedLocalPart = strtolower($principal->first_name).'.'.strtolower($principal->last_name);
        $this->assertStringStartsWith($expectedLocalPart, strstr($principal->email, '@', true));
        $this->assertMatchesRegularExpression('/@(gmail|yahoo|outlook|hotmail)\.com$/', $principal->email);
        $this->assertMatchesRegularExpression('/^0(70|80|81|90)\d{8}$/', $principal->phone);
        $this->assertMatchesRegularExpression('/^STF\/\d{4}$/', $principal->staff_id);
    }

    public function test_running_it_twice_tops_up_without_duplicating(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'seedthree']));

        $this->artisan('schools:seed-demo-data', ['school' => 'seedthree', '--per-class' => 5])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $firstRunStudents = Student::count();
        $firstRunStaff = Staff::count();
        app(TenantManager::class)->forget();

        // Same target: nothing new should be created.
        $this->artisan('schools:seed-demo-data', ['school' => 'seedthree', '--per-class' => 5])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $this->assertSame($firstRunStudents, Student::count());
        $this->assertSame($firstRunStaff, Staff::count());
        app(TenantManager::class)->forget();

        // Raising the target tops up exactly the difference, per arm.
        $this->artisan('schools:seed-demo-data', ['school' => 'seedthree', '--per-class' => 8])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $this->assertSame($firstRunStudents + (15 * 3), Student::count());
        $this->assertSame($firstRunStaff, Staff::count(), 'teachers already exist, raising the student target must not add more staff');
        app(TenantManager::class)->forget();
    }

    /**
     * Precise ages given by the user 2026-07-11: Creche/Reception 0-2,
     * Nursery 1 age 3, Nursery 2 age 4, Primary starts at 5 (one year per
     * grade), JSS 1 starts at 10 (one year per grade), Senior Secondary
     * spans 14-18 across its three grades.
     */
    public function test_students_get_an_age_matching_their_class(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'seedsix']));

        $this->artisan('schools:seed-demo-data', ['school' => 'seedsix', '--per-class' => 3])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $expectedAge = [
            'Creche/Reception' => [0, 2], 'Nursery 1' => [3, 3], 'Nursery 2' => [4, 4],
            'Primary 1' => [5, 5], 'Primary 6' => [10, 10],
            'JSS 1' => [10, 10], 'JSS 3' => [12, 12],
            'SSS 1' => [14, 15], 'SSS 3' => [16, 18],
        ];

        foreach ($expectedAge as $className => [$min, $max]) {
            $class = \App\Models\Tenant\SchoolClass::where('name', $className)->firstOrFail();
            $arm = ClassArm::where('school_class_id', $class->id)->firstOrFail();
            $student = Student::where('current_class_arm_id', $arm->id)->firstOrFail();
            $age = (int) floor(now()->diffInYears($student->date_of_birth, true));
            $this->assertTrue($age >= $min && $age <= $max, "{$className}: expected age {$min}-{$max}, got {$age}");
        }
        app(TenantManager::class)->forget();
    }

    public function test_generated_platform_users_never_collide_on_email_or_phone(): void
    {
        app(TenantManager::class)->provision(School::factory()->create(['slug' => 'seedfour']));
        app(TenantManager::class)->provision(School::factory()->create(['slug' => 'seedfive']));

        $this->artisan('schools:seed-demo-data', ['school' => 'seedfour', '--per-class' => 3])->assertSuccessful();
        $this->artisan('schools:seed-demo-data', ['school' => 'seedfive', '--per-class' => 3])->assertSuccessful();

        $emails = User::pluck('email');
        $phones = User::whereNotNull('phone')->pluck('phone');
        $this->assertSame($emails->count(), $emails->unique()->count());
        $this->assertSame($phones->count(), $phones->unique()->count());
    }
}
