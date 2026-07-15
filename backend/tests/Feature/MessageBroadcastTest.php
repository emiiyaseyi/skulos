<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\Arm;
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
use Tests\TestCase;

/**
 * Messaging increment 1 (2026-07-15): a school owner/admin sends a one-way
 * broadcast to a role group x scope; recipients acknowledge it via a
 * sign-in pop-up; the sender sees read receipts. Rich-text bodies are
 * sanitized before storage.
 */
class MessageBroadcastTest extends TestCase
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

    protected function member(School $school, string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_a_broadcast_to_parents_reaches_only_parents_and_sanitizes_the_body(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'msgone']));
        $owner = $this->member($school, 'school-owner');
        $parent = $this->member($school, 'parent');
        $teacher = $this->member($school, 'subject-teacher');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'M1', 'first_name' => 'Kid', 'last_name' => 'One']);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'P', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.messages.store', ['school' => $school->slug]), [
            'title' => 'PTA notice', 'audience' => 'parents', 'scope' => 'all',
            'body' => '<p>Please attend <strong>Saturday</strong>.</p><script>alert(1)</script>',
        ])->assertRedirect(route('school.messages.index', ['school' => $school->slug]));

        app(TenantManager::class)->use($school);
        $broadcast = Announcement::broadcasts()->first();
        $this->assertNotNull($broadcast);
        $this->assertStringContainsString('<strong>Saturday</strong>', $broadcast->body);
        $this->assertStringNotContainsString('script', $broadcast->body, 'the script tag must be stripped before storage');

        // Only the parent got a broadcast notification, not the teacher.
        $this->assertSame(1, Notification::where('category', 'broadcast')->count());
        $this->assertSame(1, Notification::where('category', 'broadcast')->where('user_id', $parent->id)->count());
        $this->assertSame(0, Notification::where('category', 'broadcast')->where('user_id', $teacher->id)->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_section_scoped_broadcast_reaches_only_that_sections_students(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'msgtwo']));
        $owner = $this->member($school, 'school-owner');
        $inSection = $this->member($school, 'student');
        $otherSection = $this->member($school, 'student');

        app(TenantManager::class)->use($school);
        $primary = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $secondary = Section::create(['name' => 'Secondary', 'sequence' => 2]);
        $pClass = SchoolClass::create(['section_id' => $primary->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $sClass = SchoolClass::create(['section_id' => $secondary->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $pArm = ClassArm::create(['school_class_id' => $pClass->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $sArm = ClassArm::create(['school_class_id' => $sClass->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        Student::create(['admission_number' => 'P1', 'first_name' => 'In', 'last_name' => 'Primary', 'current_class_arm_id' => $pArm->id, 'user_id' => $inSection->id]);
        Student::create(['admission_number' => 'S1', 'first_name' => 'In', 'last_name' => 'Secondary', 'current_class_arm_id' => $sArm->id, 'user_id' => $otherSection->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.messages.store', ['school' => $school->slug]), [
            'title' => 'Primary only', 'audience' => 'students', 'scope' => 'section',
            'section_id' => $primary->id, 'body' => '<p>Primary assembly.</p>',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(1, Notification::where('category', 'broadcast')->where('user_id', $inSection->id)->count());
        $this->assertSame(0, Notification::where('category', 'broadcast')->where('user_id', $otherSection->id)->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_recipient_sees_the_broadcast_popup_and_acknowledging_marks_it_read_for_the_sender(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'msgthree']));
        $owner = $this->member($school, 'school-owner');
        $parent = $this->member($school, 'parent');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'M3', 'first_name' => 'Kid', 'last_name' => 'Three']);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'P', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.messages.store', ['school' => $school->slug]), [
            'title' => 'Read me', 'audience' => 'parents', 'scope' => 'all', 'body' => '<p>Important.</p>',
        ]);

        // The parent sees the pop-up on their next page load.
        $this->actingAs($parent)->get(route('school.dashboard', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Acknowledge and close');

        app(TenantManager::class)->use($school);
        $notification = Notification::where('category', 'broadcast')->where('user_id', $parent->id)->first();
        app(TenantManager::class)->forget();

        // Acknowledging marks it read.
        $this->actingAs($parent)->post(route('school.notifications.read', ['school' => $school->slug, 'id' => $notification->id]));

        app(TenantManager::class)->use($school);
        $this->assertNotNull(Notification::find($notification->id)->read_at);
        app(TenantManager::class)->forget();

        // The sender's Messages screen now shows 1 of 1 acknowledged.
        $this->actingAs($owner)->get(route('school.messages.index', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('1 of 1');
    }

    public function test_a_broadcast_that_is_only_a_stripped_script_is_rejected_as_empty(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'msgfour']));
        $owner = $this->member($school, 'school-owner');

        $this->actingAs($owner)->post(route('school.messages.store', ['school' => $school->slug]), [
            'title' => 'Empty', 'audience' => 'parents', 'scope' => 'all', 'body' => '<script>alert(1)</script>',
        ])->assertSessionHasErrors('body');

        app(TenantManager::class)->use($school);
        $this->assertSame(0, Announcement::broadcasts()->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_without_broadcast_permission_cannot_compose(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'msgfive']));
        $teacher = $this->member($school, 'subject-teacher');

        $this->actingAs($teacher)->get(route('school.messages.create', ['school' => $school->slug]))->assertForbidden();
    }
}
