<?php

namespace Tests\Feature;

use App\Mail\TestMail;
use App\Models\MailLog;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Sent Mail record (2026-08-16). Two things matter here: that mail
 * is captured automatically rather than per-Mailable, and that a school
 * only ever sees its own rows.
 *
 * That second one carries real weight: MailLog lives in the PLATFORM
 * database, so unlike tenant-scoped models there is no database
 * boundary enforcing isolation — only the school_id filter. A
 * regression there would leak one school's recipient addresses to
 * another, so it is tested directly.
 */
class MailLogTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config([
            'tenancy.sqlite_path' => $this->tenantDir,
            'tenancy.driver' => 'sqlite',
            'app.domain' => 'localhost',
        ]);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    protected function schoolWithOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $owner = User::factory()->create();
        $role = app(Rbac::class)->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $owner];
    }

    public function test_sending_any_mail_records_it_without_the_mailable_doing_anything(): void
    {
        // A real send through the mailer, not Mail::fake(): the whole
        // point is that the framework's own events do the recording, and
        // faking the mailer would bypass exactly what is under test.
        config(['mail.default' => 'array']);

        Mail::to('parent@example.com')->send(new TestMail);

        $log = MailLog::first();
        $this->assertNotNull($log, 'Sending mail did not produce a log row.');
        $this->assertSame('parent@example.com', $log->recipient);
        $this->assertSame('SKULOS test email', $log->subject);
        $this->assertSame(MailLog::STATUS_SENT, $log->status);
        $this->assertNotNull($log->sent_at);
    }

    public function test_a_school_only_sees_its_own_sent_mail(): void
    {
        [$schoolA, $ownerA] = $this->schoolWithOwner('mailone');
        [$schoolB] = $this->schoolWithOwner('mailtwo');

        MailLog::create(['recipient' => 'a-parent@example.com', 'subject' => 'For school A', 'status' => MailLog::STATUS_SENT, 'school_id' => $schoolA->id]);
        MailLog::create(['recipient' => 'b-parent@example.com', 'subject' => 'For school B', 'status' => MailLog::STATUS_SENT, 'school_id' => $schoolB->id]);
        MailLog::create(['recipient' => 'lead@example.com', 'subject' => 'Platform only', 'status' => MailLog::STATUS_SENT]);

        $response = $this->actingAs($ownerA)->get("http://{$schoolA->slug}.localhost/mail-log");

        $response->assertOk()
            ->assertSee('a-parent@example.com')
            ->assertDontSee('b-parent@example.com')
            ->assertDontSee('lead@example.com');
    }

    public function test_the_platform_owner_sees_every_schools_mail(): void
    {
        [$schoolA] = $this->schoolWithOwner('mailthree');
        $admin = $this->superAdmin();

        MailLog::create(['recipient' => 'a-parent@example.com', 'subject' => 'School mail', 'status' => MailLog::STATUS_SENT, 'school_id' => $schoolA->id]);
        MailLog::create(['recipient' => 'lead@example.com', 'subject' => 'Trial request', 'status' => MailLog::STATUS_FAILED]);

        $this->actingAs($admin)->get(route('admin.mail-log.index'))
            ->assertOk()
            ->assertSee('a-parent@example.com')
            ->assertSee('lead@example.com');
    }

    public function test_a_stalled_row_reports_as_failed_rather_than_pretending_to_be_in_flight(): void
    {
        // Laravel emits no "failed" mail event, so a row left at
        // "sending" is what a failure actually looks like.
        $log = MailLog::create(['recipient' => 'x@example.com', 'status' => MailLog::STATUS_SENDING]);
        $log->forceFill(['created_at' => now()->subHour()])->save();

        $this->assertSame(MailLog::STATUS_FAILED, $log->fresh()->effectiveStatus());
    }

    public function test_a_member_without_communication_permission_cannot_view_it(): void
    {
        [$school] = $this->schoolWithOwner('mailfour');
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get("http://{$school->slug}.localhost/mail-log");
        $this->assertContains($response->getStatusCode(), [403, 404, 302]);
    }

    public function test_a_mailable_carrying_its_own_school_is_attributed_to_that_school_with_no_tenant_connection_active(): void
    {
        // The exact real gap found 2026-08-17: SchoolWelcomeMail and an
        // approved TrialRequestStatusMail are both sent from plain
        // platform admin controllers with no tenant connection active,
        // so relying on TenantManager::current() alone silently
        // recorded every one of them as belonging to no school at all.
        [$school, $owner] = $this->schoolWithOwner('mailfive');
        app(TenantManager::class)->forget();
        config(['mail.default' => 'array']);

        Mail::to($owner->email)->send(new \App\Mail\SchoolWelcomeMail($school, [
            'portal_url' => $school->portalUrl('/login'), 'email' => $owner->email, 'password' => 'x',
        ]));

        $log = MailLog::where('mailable', \App\Mail\SchoolWelcomeMail::class)->first();
        $this->assertNotNull($log);
        $this->assertSame($school->id, $log->school_id);
    }

    public function test_account_created_mail_records_its_own_audience_from_the_role_it_provisioned(): void
    {
        [$school, $owner] = $this->schoolWithOwner('mailsix');
        config(['mail.default' => 'array']);

        Mail::to('a-parent@example.com')->send(new \App\Mail\AccountCreatedMail(
            $owner, $school, 'x', 'Parent', 'parent'
        ));

        $log = MailLog::where('mailable', \App\Mail\AccountCreatedMail::class)->first();
        $this->assertSame(MailLog::AUDIENCE_PARENT, $log->audience);
        $this->assertSame($school->id, $log->school_id);
    }

    public function test_a_password_reset_mail_derives_its_audience_from_the_recipients_real_role(): void
    {
        [$school, $owner] = $this->schoolWithOwner('mailseven');
        config(['mail.default' => 'array']);

        Mail::to($owner->email)->send(new \App\Mail\PasswordResetByAdminMail($owner, $school, 'newpass'));

        $log = MailLog::where('mailable', \App\Mail\PasswordResetByAdminMail::class)->first();
        // schoolWithOwner() cloned the 'school-owner' role, a staff-tier
        // role, not one of parent/student.
        $this->assertSame(MailLog::AUDIENCE_STAFF, $log->audience);
    }

    public function test_a_trial_applicant_has_no_audience_forced_onto_it(): void
    {
        config(['mail.default' => 'array']);
        $trial = \App\Models\TrialRequest::create([
            'school_name' => 'Prospect School', 'contact_name' => 'Ada', 'email' => 'ada@example.com',
            'phone' => '0800', 'status' => \App\Models\TrialRequest::STATUS_PENDING,
        ]);

        Mail::to($trial->email)->send(new \App\Mail\TrialRequestStatusMail($trial));

        $log = MailLog::where('mailable', \App\Mail\TrialRequestStatusMail::class)->first();
        // Not one of the four real buckets -- forcing a guess here would
        // misrepresent the report, not inform it.
        $this->assertNull($log->audience);
    }
}
