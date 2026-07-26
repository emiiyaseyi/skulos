<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Notification;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Send Announcement (Dashboard Design Cross-Check, 2026-07-13: "Blocked,
 * announcements can be viewed now, not composed, no compose screen yet").
 * The delivery pipeline (Announcement, NotificationService, email) already
 * existed and worked, this covers the new compose screen in front of it.
 */
class AnnouncementComposeTest extends TestCase
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

    public function test_owner_can_send_an_announcement_to_everyone(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'annone');
        $teacher = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'teacher');
        $school->users()->attach($teacher->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        $this->actingAs($owner)->post(route('school.announcements.store', ['school' => $school->slug]), [
            'title' => 'School closed Friday', 'body' => 'No classes this Friday.', 'audience' => 'all',
        ])->assertRedirect(route('school.announcements.index', ['school' => $school->slug]));

        app(TenantManager::class)->use($school);
        $announcement = Announcement::first();
        $this->assertTrue($announcement->isPublished());
        $this->assertGreaterThan(0, Notification::where('announcement_id', $announcement->id)->count());
        app(TenantManager::class)->forget();
    }

    public function test_sending_to_one_class_reaches_only_that_class_and_its_guardians(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'anntwo');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $studentUser = User::factory()->create();
        $student = Student::create(['admission_number' => 'AN1', 'first_name' => 'A', 'last_name' => 'One', 'current_class_arm_id' => $arm->id, 'user_id' => $studentUser->id]);
        $guardianUser = User::factory()->create();
        StudentGuardian::create(['student_id' => $student->id, 'guardian_user_id' => $guardianUser->id, 'name' => 'Guardian One']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.announcements.store', ['school' => $school->slug]), [
            'title' => 'Class trip', 'body' => 'Permission slips due Monday.', 'audience' => 'class', 'class_arm_id' => $arm->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $announcement = Announcement::first();
        $recipientIds = Notification::where('announcement_id', $announcement->id)->pluck('user_id')->all();
        app(TenantManager::class)->forget();

        $this->assertContains($studentUser->id, $recipientIds);
        $this->assertContains($guardianUser->id, $recipientIds);
        $this->assertCount(2, $recipientIds);
    }

    public function test_a_teacher_without_broadcast_permission_cannot_compose(): void
    {
        [$school, $teacher] = $this->schoolWithMember('teacher', 'annthree');

        $this->actingAs($teacher)->get(route('school.announcements.create', ['school' => $school->slug]))->assertForbidden();
    }

    public function test_the_announcement_appears_in_the_index_list(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'annfour');

        $this->actingAs($owner)->post(route('school.announcements.store', ['school' => $school->slug]), [
            'title' => 'Uniform update', 'body' => 'New uniform supplier starting next term.', 'audience' => 'all',
        ]);

        $this->actingAs($owner)->get(route('school.announcements.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('Uniform update');
    }

    /**
     * User report 2026-07-26: the index list truncated every body to 90
     * characters with no way to open the rest, "one should be able to
     * click on each one so as to read it fully."
     */
    public function test_a_recipient_can_click_through_to_read_the_full_announcement(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'annfive');
        $longBody = str_repeat('This is the full announcement body. ', 10);

        $this->actingAs($owner)->post(route('school.announcements.store', ['school' => $school->slug]), [
            'title' => 'Long notice', 'body' => $longBody, 'audience' => 'all',
        ]);

        app(TenantManager::class)->use($school);
        $announcementId = Announcement::first()->id;
        app(TenantManager::class)->forget();

        $index = $this->actingAs($owner)->get(route('school.announcements.index', ['school' => $school->slug]));
        $index->assertOk()->assertSee(route('school.announcements.show', ['school' => $school->slug, 'announcementId' => $announcementId]), false);

        $this->actingAs($owner)->get(route('school.announcements.show', ['school' => $school->slug, 'announcementId' => $announcementId]))
            ->assertOk()->assertSee('Long notice')->assertSee(trim($longBody));
    }
}
