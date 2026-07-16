<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Notification;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Birthday & Anniversary Notifications (user directive 2026-07-16, "ALL
 * THERE": build out the remaining Reports & Analytics/Communication
 * "Soon" items). The dashboard already had a passive "Birthdays" widget
 * (students only, nothing ever pushed to anyone); this is a genuinely
 * different thing, real Notification rows created the moment a birthday
 * or work anniversary actually falls on today.
 */
class BirthdayAnniversaryNotificationsTest extends TestCase
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

    protected function schoolWithOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $owner = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $owner];
    }

    public function test_a_students_birthday_today_notifies_leadership_and_their_class_teacher(): void
    {
        [$school, $owner] = $this->schoolWithOwner('bdayone');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $teacherUser = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacherUser->id, ['role_id' => $teacherRole->id, 'status' => 'active']);
        $staff = Staff::create(['staff_id' => 'STF/1', 'first_name' => 'C', 'last_name' => 'T', 'status' => 'active', 'user_id' => $teacherUser->id]);
        $arm->update(['class_teacher_staff_id' => $staff->id]);
        Student::create([
            'admission_number' => 'BD1', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'current_class_arm_id' => $arm->id, 'date_of_birth' => now()->subYears(10)->format('Y-m-d'),
        ]);
        app(TenantManager::class)->forget();

        $this->artisan('skulos:notify-birthdays-anniversaries', ['school' => 'bdayone'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $ownerNotified = Notification::where('user_id', $owner->id)->where('category', 'birthday')->exists();
        $teacherNotified = Notification::where('user_id', $teacherUser->id)->where('category', 'birthday')->exists();
        $note = Notification::where('category', 'birthday')->first();
        app(TenantManager::class)->forget();

        $this->assertTrue($ownerNotified, 'leadership must be notified of a student birthday');
        $this->assertTrue($teacherNotified, "the student's own class teacher must be notified too");
        $this->assertStringContainsString('Ada Obi', $note->title);
        // A precise match, not just assertStringContainsString('10', ...),
        // which would also (wrongly) pass for "-10" — this is exactly the
        // kind of assertion that let a real sign bug slip through
        // undetected: Carbon 3 changed diffInYears()'s default from
        // absolute to signed, so $today->diffInYears($pastDate) without
        // passing true now returns a NEGATIVE number for a birthday that
        // has already happened this year, silently breaking the "at
        // least 1 year" anniversary guard below and text like "turns -10
        // today" for a birthday, caught here by checking the exact
        // substring "turns 10" rather than a bare "10".
        $this->assertStringContainsString('turns 10', $note->body);
    }

    public function test_a_staff_members_birthday_and_work_anniversary_notify_leadership(): void
    {
        [$school, $owner] = $this->schoolWithOwner('bdaytwo');

        app(TenantManager::class)->use($school);
        Staff::create([
            'staff_id' => 'STF/2', 'first_name' => 'Bola', 'last_name' => 'Ade', 'status' => 'active',
            'date_of_birth' => now()->subYears(35)->format('Y-m-d'),
            'employed_on' => now()->subYears(3)->format('Y-m-d'),
        ]);
        app(TenantManager::class)->forget();

        $this->artisan('skulos:notify-birthdays-anniversaries', ['school' => 'bdaytwo'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $birthday = Notification::where('user_id', $owner->id)->where('category', 'birthday')->first();
        $anniversary = Notification::where('user_id', $owner->id)->where('category', 'anniversary')->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($birthday);
        $this->assertStringContainsString('Bola Ade', $birthday->title);
        $this->assertNotNull($anniversary);
        $this->assertStringContainsString('3 year', $anniversary->body);
    }

    public function test_a_staff_member_hired_today_this_year_is_not_yet_an_anniversary(): void
    {
        [$school, $owner] = $this->schoolWithOwner('bdaythree');

        app(TenantManager::class)->use($school);
        Staff::create([
            'staff_id' => 'STF/3', 'first_name' => 'New', 'last_name' => 'Hire', 'status' => 'active',
            'employed_on' => now()->format('Y-m-d'),
        ]);
        app(TenantManager::class)->forget();

        $this->artisan('skulos:notify-birthdays-anniversaries', ['school' => 'bdaythree'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $anniversary = Notification::where('category', 'anniversary')->exists();
        app(TenantManager::class)->forget();

        $this->assertFalse($anniversary, 'the hire date itself is not a work anniversary yet');
    }

    public function test_running_it_twice_in_one_day_does_not_duplicate_notifications(): void
    {
        [$school, $owner] = $this->schoolWithOwner('bdayfour');

        app(TenantManager::class)->use($school);
        Staff::create(['staff_id' => 'STF/4', 'first_name' => 'One', 'last_name' => 'Time', 'status' => 'active', 'date_of_birth' => now()->subYears(40)->format('Y-m-d')]);
        app(TenantManager::class)->forget();

        $this->artisan('skulos:notify-birthdays-anniversaries', ['school' => 'bdayfour']);
        $this->artisan('skulos:notify-birthdays-anniversaries', ['school' => 'bdayfour']);

        app(TenantManager::class)->use($school);
        $count = Notification::where('user_id', $owner->id)->where('category', 'birthday')->count();
        app(TenantManager::class)->forget();

        $this->assertSame(1, $count, 'the second run on the same day must not create a duplicate');
    }

    public function test_someone_with_no_birthday_or_anniversary_today_gets_nothing(): void
    {
        [$school, $owner] = $this->schoolWithOwner('bdayfive');

        app(TenantManager::class)->use($school);
        Staff::create(['staff_id' => 'STF/5', 'first_name' => 'Not', 'last_name' => 'Today', 'status' => 'active', 'date_of_birth' => now()->subYears(1)->subDays(20)->format('Y-m-d')]);
        app(TenantManager::class)->forget();

        $this->artisan('skulos:notify-birthdays-anniversaries', ['school' => 'bdayfive']);

        app(TenantManager::class)->use($school);
        $count = Notification::count();
        app(TenantManager::class)->forget();

        $this->assertSame(0, $count);
    }
}
