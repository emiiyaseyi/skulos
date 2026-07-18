<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\ChatThread;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\User;
use App\Services\Communication\ChatService;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Messaging increment 4 (2026-07-15): private 1-to-1 chat between a
 * teacher and a student's guardian, about that student, plus the admin
 * toggle to turn off the teacher-to-parent direction.
 */
class ChatTest extends TestCase
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
        Storage::fake('public');
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

    /**
     * A school, a class-teacher assigned to one class arm, a student in
     * that arm, and the student's guardian (with a login). Returns
     * everything a test needs, and leaves the tenant connection torn down.
     */
    protected function schoolWithTeacherStudentGuardian(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $teacher = $this->member($school, 'class-teacher');
        $parent = $this->member($school, 'parent');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tee', 'last_name' => 'Cher', 'position' => 'Class Teacher', 'status' => 'active', 'user_id' => $teacher->id]);
        $arm->update(['class_teacher_staff_id' => $staff->id]);
        $student = Student::create(['admission_number' => 'A/001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Mrs Obi', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        return [$school, $teacher, $parent, $studentId];
    }

    public function test_a_teacher_can_start_and_send_a_chat_to_their_students_guardian(): void
    {
        [$school, $teacher, $parent, $studentId] = $this->schoolWithTeacherStudentGuardian('chatone');

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/chat/start?student_id={$studentId}&user_id={$parent->id}");
        $response->assertRedirect();
        $threadUrl = $response->headers->get('Location');

        $this->actingAs($teacher)->get($threadUrl)->assertOk();

        app(TenantManager::class)->use($school);
        $thread = ChatThread::first();
        $threadId = $thread->id;
        app(TenantManager::class)->forget();
        $this->assertNotNull($thread);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/chat/{$threadId}/messages", [
            'body' => 'Hello, quick update on Ada.',
        ])->assertRedirect();

        $response = $this->actingAs($parent)->get("http://{$school->slug}.localhost/chat/{$threadId}");
        $response->assertOk()->assertSee('Hello, quick update on Ada.');
    }

    public function test_a_teacher_cannot_message_a_guardian_of_a_student_not_in_their_class(): void
    {
        [$school, $teacher] = $this->schoolWithTeacherStudentGuardian('chattwo');
        $outsideParent = $this->member($school, 'parent');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 2]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $otherStudent = Student::create(['admission_number' => 'B/001', 'first_name' => 'Bode', 'last_name' => 'Ade', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $otherStudent->id, 'name' => 'Mr Ade', 'guardian_user_id' => $outsideParent->id]);
        $otherStudentId = $otherStudent->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/chat/start?student_id={$otherStudentId}&user_id={$outsideParent->id}")
            ->assertNotFound();
    }

    /** ChatService::isGuardianOf() must not let a "student" role pass as their own guardian. */
    public function test_a_student_cannot_use_the_guardian_pairing_to_chat_with_their_own_teacher(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'chatthree']));
        $teacher = $this->member($school, 'class-teacher');
        $studentUser = $this->member($school, 'student');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tee', 'last_name' => 'Cher', 'position' => 'Class Teacher', 'status' => 'active', 'user_id' => $teacher->id]);
        $arm->update(['class_teacher_staff_id' => $staff->id]);
        $student = Student::create(['admission_number' => 'A/001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $arm->id, 'user_id' => $studentUser->id]);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($studentUser)->get("http://{$school->slug}.localhost/chat/start?student_id={$studentId}&user_id={$teacher->id}")
            ->assertNotFound();
    }

    public function test_turning_off_teacher_parent_messaging_blocks_a_teacher_from_starting_or_sending(): void
    {
        [$school, $teacher, $parent, $studentId] = $this->schoolWithTeacherStudentGuardian('chatfour');

        app(TenantManager::class)->use($school);
        app(ChatService::class)->setTeacherParentMessagingEnabled(false);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/chat/start?student_id={$studentId}&user_id={$parent->id}")
            ->assertRedirect(route('school.dashboard', ['school' => $school->slug]));
    }

    public function test_a_parent_can_still_reply_in_an_existing_thread_when_teacher_parent_messaging_is_off(): void
    {
        [$school, $teacher, $parent, $studentId] = $this->schoolWithTeacherStudentGuardian('chatfive');

        // Start the thread and let the teacher send one message while still enabled.
        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/chat/start?student_id={$studentId}&user_id={$parent->id}");
        app(TenantManager::class)->use($school);
        $threadId = ChatThread::first()->id;
        app(TenantManager::class)->forget();
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/chat/{$threadId}/messages", ['body' => 'First message.']);

        // Now turn it off.
        app(TenantManager::class)->use($school);
        app(ChatService::class)->setTeacherParentMessagingEnabled(false);
        app(TenantManager::class)->forget();

        // The parent can still reply.
        $this->actingAs($parent)->post("http://{$school->slug}.localhost/chat/{$threadId}/messages", ['body' => 'Thanks, noted.'])
            ->assertRedirect();

        // But the teacher cannot send a further message.
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/chat/{$threadId}/messages", ['body' => 'One more thing.'])
            ->assertSessionHasErrors('body');

        app(TenantManager::class)->use($school);
        $bodies = \App\Models\Tenant\ChatMessage::where('thread_id', $threadId)->pluck('body');
        app(TenantManager::class)->forget();
        $this->assertTrue($bodies->contains('Thanks, noted.'));
        $this->assertFalse($bodies->contains('One more thing.'));
    }

    public function test_a_message_can_carry_a_light_image_converted_to_webp(): void
    {
        [$school, $teacher, $parent, $studentId] = $this->schoolWithTeacherStudentGuardian('chatsix');
        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/chat/start?student_id={$studentId}&user_id={$parent->id}");
        app(TenantManager::class)->use($school);
        $threadId = ChatThread::first()->id;
        app(TenantManager::class)->forget();

        $image = UploadedFile::fake()->image('photo.jpg', 600, 400);
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/chat/{$threadId}/messages", ['image' => $image])
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $message = \App\Models\Tenant\ChatMessage::where('thread_id', $threadId)->first();
        app(TenantManager::class)->forget();
        $this->assertStringEndsWith('.webp', $message->image_path);
        Storage::disk('public')->assertExists($message->image_path);
    }

    public function test_opening_a_thread_marks_the_other_participants_messages_read(): void
    {
        [$school, $teacher, $parent, $studentId] = $this->schoolWithTeacherStudentGuardian('chatseven');
        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/chat/start?student_id={$studentId}&user_id={$parent->id}");
        app(TenantManager::class)->use($school);
        $threadId = ChatThread::first()->id;
        app(TenantManager::class)->forget();
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/chat/{$threadId}/messages", ['body' => 'Hello.']);

        app(TenantManager::class)->use($school);
        $unreadForParent = ChatThread::find($threadId)->unreadCountFor($parent->id);
        app(TenantManager::class)->forget();
        $this->assertSame(1, $unreadForParent);

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/chat/{$threadId}")->assertOk();

        app(TenantManager::class)->use($school);
        $unreadAfter = ChatThread::find($threadId)->unreadCountFor($parent->id);
        app(TenantManager::class)->forget();
        $this->assertSame(0, $unreadAfter);
    }

    public function test_the_message_button_shows_on_the_students_page_for_the_teacher_and_guardian(): void
    {
        [$school, $teacher, $parent, $studentId] = $this->schoolWithTeacherStudentGuardian('chateight');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/students/{$studentId}")
            ->assertOk()->assertSee('Message');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/students/{$studentId}")
            ->assertOk()->assertSee("Message {$teacher->name}");
    }

    public function test_school_owner_can_toggle_teacher_parent_messaging_off(): void
    {
        [$school, $teacher, $parent, $studentId] = $this->schoolWithTeacherStudentGuardian('chatnine');
        $owner = $this->member($school, 'school-owner');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/communication", [])
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $enabled = app(ChatService::class)->teacherParentMessagingEnabled();
        app(TenantManager::class)->forget();
        $this->assertFalse($enabled, 'omitting the checkbox from the POST must turn it off');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/communication", [
            'teacher_parent_messaging_enabled' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $enabledAgain = app(ChatService::class)->teacherParentMessagingEnabled();
        app(TenantManager::class)->forget();
        $this->assertTrue($enabledAgain);
    }

    /** Email Notifications toggle (2026-07-18) lives on the same settings screen/form as the messaging toggle above. */
    public function test_school_owner_can_toggle_email_notifications_on_the_same_screen(): void
    {
        [$school, ] = $this->schoolWithTeacherStudentGuardian('chatten');
        $owner = $this->member($school, 'school-owner');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/communication", [
            'teacher_parent_messaging_enabled' => '1',
            'email_notifications_enabled' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $enabled = app(\App\Services\Communication\CommunicationService::class)->channelEnabled('email');
        app(TenantManager::class)->forget();
        $this->assertTrue($enabled);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/communication", [
            'teacher_parent_messaging_enabled' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $disabled = app(\App\Services\Communication\CommunicationService::class)->channelEnabled('email');
        app(TenantManager::class)->forget();
        $this->assertFalse($disabled, 'omitting the checkbox from the POST must turn it off');
    }
}
