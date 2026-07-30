<?php

namespace Tests\Feature;

use App\Jobs\SendAnnouncementEmailJob;
use App\Mail\AnnouncementMail;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\CommunicationLog;
use App\Services\Communication\CommunicationService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommunicationTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'comms', 'name' => 'Comms High']));
        app(TenantManager::class)->use($school);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_publishing_emails_recipients_and_logs_each_send(): void
    {
        Mail::fake();

        $announcement = Announcement::create([
            'title' => 'Resumption Notice',
            'body' => "Dear parents,\nSchool resumes on Monday.",
            'audience' => 'parents',
        ]);

        $outcome = app(CommunicationService::class)->publishAnnouncement($announcement, [
            'a@example.com', 'b@example.com', 'a@example.com', // duplicate ignored
        ]);

        $this->assertSame(['queued' => 2], $outcome);
        $this->assertTrue($announcement->fresh()->isPublished());

        Mail::assertSent(AnnouncementMail::class, 2);
        Mail::assertSent(AnnouncementMail::class, fn ($m) => $m->hasTo('a@example.com') && $m->subjectLine === 'Resumption Notice');

        $this->assertSame(2, CommunicationLog::where('channel', 'email')->where('status', 'sent')->count());
        $this->assertSame(2, $announcement->logs()->count());
    }

    /**
     * The other tests in this file run under the 'sync' queue driver
     * (phpunit.xml), so a dispatched job executes inline and its side
     * effects (Mail::assertSent, CommunicationLog status) look identical
     * to the old synchronous-send code. This test proves the actual fix
     * (audit follow-up 2026-07-30): the send is a genuinely separate,
     * queued unit of work, not just synchronous code that happens to run
     * immediately in this environment. Each log starts 'queued' and stays
     * that way until its job runs, which under a real queue driver in
     * production is off the request thread entirely.
     */
    public function test_publishing_dispatches_one_queued_job_per_recipient_instead_of_sending_inline(): void
    {
        Mail::fake();
        Queue::fake();

        $school = app(TenantManager::class)->current();
        $announcement = Announcement::create(['title' => 'Queued Notice', 'body' => 'Body', 'audience' => 'parents']);

        $outcome = app(CommunicationService::class)->publishAnnouncement($announcement, ['a@example.com', 'b@example.com']);

        $this->assertSame(['queued' => 2], $outcome);
        Mail::assertNothingSent();
        $this->assertSame(2, CommunicationLog::where('status', CommunicationLog::STATUS_QUEUED)->count());

        Queue::assertPushed(SendAnnouncementEmailJob::class, 2);
        Queue::assertPushed(SendAnnouncementEmailJob::class, fn ($job) => $job->schoolId === $school->id && $job->email === 'a@example.com' && $job->subjectLine === 'Queued Notice');
    }

    public function test_email_channel_can_be_disabled(): void
    {
        Mail::fake();
        config(['skulos.communication_channels.email.enabled' => false]);

        $announcement = Announcement::create(['title' => 'Hi', 'body' => 'Test', 'audience' => 'all']);
        $outcome = app(CommunicationService::class)->publishAnnouncement($announcement, ['x@example.com']);

        $this->assertSame(['queued' => 0], $outcome);
        Mail::assertNothingSent();
        $this->assertSame(0, CommunicationLog::count());
    }

    public function test_publishing_is_audited(): void
    {
        Mail::fake();
        $announcement = Announcement::create(['title' => 'Hi', 'body' => 'Test', 'audience' => 'all']);
        app(CommunicationService::class)->publishAnnouncement($announcement, ['x@example.com']);

        $this->assertTrue(
            AuditLog::on('tenant')->where('action', 'announcement.published')->exists()
        );
    }

    /**
     * Email Notifications (Advance plan feature 'email_notifications',
     * wired 2026-07-18): the delivery itself always worked, but there was
     * no school-facing way to turn it off, channelEnabled('email') always
     * read the hardcoded config default. Proves the new Setting-backed
     * toggle actually controls delivery, not just the config fallback the
     * test above already covers.
     */
    public function test_email_channel_can_be_disabled_via_the_school_setting(): void
    {
        Mail::fake();
        app(CommunicationService::class)->setEmailChannelEnabled(false);

        $announcement = Announcement::create(['title' => 'Hi', 'body' => 'Test', 'audience' => 'all']);
        $outcome = app(CommunicationService::class)->publishAnnouncement($announcement, ['x@example.com']);

        $this->assertSame(['queued' => 0], $outcome);
        Mail::assertNothingSent();

        // Turning it back on takes effect immediately, no cache to bust.
        app(CommunicationService::class)->setEmailChannelEnabled(true);
        $announcement2 = Announcement::create(['title' => 'Hi Again', 'body' => 'Test', 'audience' => 'all']);
        $outcome2 = app(CommunicationService::class)->publishAnnouncement($announcement2, ['x@example.com']);
        $this->assertSame(['queued' => 1], $outcome2);
    }

    public function test_one_off_email_is_logged(): void
    {
        Mail::fake();

        $outcome = app(CommunicationService::class)->sendEmail(
            ['parent@example.com'], 'Fee Reminder', 'Your term fees are due.'
        );

        $this->assertSame(['queued' => 1], $outcome);
        Mail::assertSent(AnnouncementMail::class, 1);
        $this->assertSame(1, CommunicationLog::where('subject', 'Fee Reminder')->where('status', 'sent')->count());
    }

    /**
     * A real queue worker process has no "current tenant" at all when it
     * picks up a job, unlike every other test in this file, which always
     * runs with TenantManager::use() already pointed at a school from
     * setUp(). This is the scenario the job's own connectTo() call exists
     * for: reproduces it directly by forgetting the tenant before running
     * the job, the same blank state `queue:work` starts from in
     * production, and confirms it still resolves the right school,
     * updates that school's own CommunicationLog row, and leaves no
     * tenant connection behind afterward.
     */
    public function test_the_job_establishes_its_own_tenant_connection_when_run_with_none_active(): void
    {
        Mail::fake();

        $school = School::where('slug', 'comms')->firstOrFail();
        $log = CommunicationLog::create([
            'channel' => 'email',
            'recipient' => 'worker@example.com',
            'subject' => 'From a worker',
            'status' => CommunicationLog::STATUS_QUEUED,
        ]);

        app(TenantManager::class)->forget();
        $this->assertNull(app(TenantManager::class)->current());

        (new SendAnnouncementEmailJob($school->id, $log->id, 'worker@example.com', 'From a worker', 'Body', $school->name))
            ->handle(app(TenantManager::class), app(\App\Services\Mail\MailSettingsService::class));

        Mail::assertSent(AnnouncementMail::class, fn ($m) => $m->hasTo('worker@example.com'));
        $this->assertNull(app(TenantManager::class)->current(), 'the job must restore no-tenant state, not leave the connection pointed at the school it just used');

        app(TenantManager::class)->use($school);
        $this->assertSame(CommunicationLog::STATUS_SENT, $log->fresh()->status);
        app(TenantManager::class)->forget();

        $this->assertNull(app(TenantManager::class)->current());
    }
}
