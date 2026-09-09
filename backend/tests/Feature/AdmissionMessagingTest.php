<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AdmissionMessage;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A real two-way message thread between school staff and an applicant
 * (user directive 2026-09-09), distinct from the one-way
 * AdmissionReminderMail nudge.
 */
class AdmissionMessagingTest extends TestCase
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

    /** @return array{0: School, 1: User, 2: Applicant} */
    protected function fixture(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug, 'name' => 'Greenfield Academy']));
        $this->rbac->setModuleState($school, 'admissions', Module::STATE_ENABLED);

        $admin = User::factory()->create(['name' => 'Jane Registrar']);
        $adminRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($admin->id, ['role_id' => $adminRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $session = AcademicSession::create(['name' => '2026/2027 Session', 'is_current' => true]);
        AdmissionWindow::create([
            'session_id' => $session->id, 'school_class_id' => $class->id,
            'application_fee_amount' => 5000, 'is_active' => true,
        ]);
        $applicant = Applicant::forceCreate([
            'name' => 'Ada Obi', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'phone' => '08011112222', 'email' => 'parent-ada@example.com',
            'admission_number' => 'APP/0001', 'password' => Hash::make('secret123'),
            'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
            'session_id' => $session->id, 'school_class_id' => $class->id,
        ]);
        app(TenantManager::class)->forget();

        return [$school, $admin, $applicant];
    }

    protected function loginAsApplicant(School $school, string $identifier = 'APP/0001', string $password = 'secret123'): void
    {
        $this->post("http://{$school->slug}.localhost/admissions/login", ['identifier' => $identifier, 'password' => $password])
            ->assertRedirect();
    }

    public function test_staff_can_send_a_message_and_the_applicant_sees_it_on_their_dashboard(): void
    {
        Mail::fake();
        [$school, $admin, $applicant] = $this->fixture('admsgone');

        $this->actingAs($admin)->post(route('school.admissions.pipeline.messages.send', ['school' => $school->slug, 'applicantId' => $applicant->id]), [
            'body' => 'Please bring your birth certificate on Monday.',
        ])->assertRedirect();

        Mail::assertSent(\App\Mail\AdmissionMessageMail::class);

        app(TenantManager::class)->use($school);
        $this->assertSame(1, AdmissionMessage::count());
        $saved = AdmissionMessage::first();
        $this->assertSame(AdmissionMessage::SENDER_STAFF, $saved->sender);
        $this->assertSame($admin->id, $saved->sender_user_id);
        app(TenantManager::class)->forget();

        $this->loginAsApplicant($school);
        $this->get("http://{$school->slug}.localhost/admissions/dashboard")
            ->assertOk()
            ->assertSee('Please bring your birth certificate on Monday.')
            ->assertSee('Jane Registrar');
    }

    public function test_an_applicant_reply_shows_on_the_staff_thread_and_flags_unread_on_the_board(): void
    {
        [$school, $admin, $applicant] = $this->fixture('admsgtwo');

        $this->loginAsApplicant($school);
        $this->post("http://{$school->slug}.localhost/admissions/messages/reply", [
            'body' => 'I have a question about the acceptance fee.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $saved = AdmissionMessage::first();
        $this->assertSame(AdmissionMessage::SENDER_APPLICANT, $saved->sender);
        $this->assertNull($saved->sender_user_id);
        $this->assertNull($saved->read_at);
        app(TenantManager::class)->forget();

        // Unread flag shows on the board without opening the thread.
        // A bare "New" would risk matching something unrelated, so this
        // checks for the actual badge markup the row renders.
        $this->actingAs($admin)->get(route('school.admissions.pipeline', ['school' => $school->slug]))
            ->assertOk()->assertSee('badge badge-warn', false)->assertSee('>New<', false);

        // Opening the thread shows the message and marks it read.
        $this->actingAs($admin)->get(route('school.admissions.pipeline.messages', ['school' => $school->slug, 'applicantId' => $applicant->id]))
            ->assertOk()->assertSee('I have a question about the acceptance fee.');

        app(TenantManager::class)->use($school);
        $this->assertNotNull(AdmissionMessage::first()->read_at);
        app(TenantManager::class)->forget();
    }

    public function test_a_role_without_admissions_decide_cannot_open_or_send_messages(): void
    {
        [$school, , $applicant] = $this->fixture('admsgthree');

        $teacher = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'subject-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        $this->actingAs($teacher)->get(route('school.admissions.pipeline.messages', ['school' => $school->slug, 'applicantId' => $applicant->id]))
            ->assertForbidden();

        $this->actingAs($teacher)->post(route('school.admissions.pipeline.messages.send', ['school' => $school->slug, 'applicantId' => $applicant->id]), [
            'body' => 'Should not be allowed.',
        ])->assertForbidden();
    }
}
