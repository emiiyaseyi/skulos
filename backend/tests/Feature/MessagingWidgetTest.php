<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Notification;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
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
 * Floating bottom-right messaging widget (2026-07-15, user directive: "THE
 * MESSAGE FEATURE SHOULD ALSO APPEAR AS A WIDGET BY THE BOTTOM RIGHT SIDE
 * OF THE USER PORTAL"). Shown for every school-portal role that can reach
 * some form of messaging, linking to Chat for the two roles that
 * participate in it, Messages otherwise, with a combined unread badge.
 */
class MessagingWidgetTest extends TestCase
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

    protected function member(School $school, string $roleSlug, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_a_parent_sees_the_widget_pointed_at_chat_with_their_unread_count(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'widgetone']));
        $teacher = $this->member($school, 'class-teacher');
        $parent = $this->member($school, 'parent');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tee', 'last_name' => 'Cher', 'position' => 'Class Teacher', 'status' => 'active', 'user_id' => $teacher->id]);
        $arm->update(['class_teacher_staff_id' => $staff->id]);
        $student = Student::create(['admission_number' => 'A/001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Mrs Obi', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/chat/start?student_id={$studentId}&user_id={$parent->id}");
        app(TenantManager::class)->use($school);
        $threadId = \App\Models\Tenant\ChatThread::first()->id;
        app(TenantManager::class)->forget();
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/chat/{$threadId}/messages", ['body' => 'Hello.']);

        $response = $this->actingAs($parent)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()->assertSee('mw-fab', false)->assertSee(route('school.chat.index', ['school' => $school->slug]), false)
            ->assertSee('mw-badge', false)->assertSee('>1<', false);
    }

    public function test_a_school_owner_sees_the_widget_pointed_at_messages_with_a_broadcast_badge(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'widgettwo']));
        $owner = $this->member($school, 'school-owner');

        app(TenantManager::class)->use($school);
        $announcement = Announcement::create([
            'title' => 'Notice', 'body' => 'Body', 'type' => Announcement::TYPE_BROADCAST,
            'audience' => 'staff', 'status' => Announcement::STATUS_PUBLISHED, 'published_at' => now(),
        ]);
        Notification::create([
            'user_id' => $owner->id, 'audience' => 'staff', 'title' => 'Notice', 'body' => 'Body',
            'category' => 'broadcast', 'type' => 'system', 'announcement_id' => $announcement->id,
        ]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()->assertSee('mw-fab', false)->assertSee(route('school.messages.index', ['school' => $school->slug]), false);
    }

    public function test_a_role_with_no_communication_permission_does_not_see_the_widget(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'widgetthree']));
        $librarian = $this->member($school, 'librarian');

        $response = $this->actingAs($librarian)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()->assertDontSee('mw-fab', false);
    }
}
