<?php

namespace Tests\Feature;

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

        $this->assertSame(['sent' => 2, 'failed' => 0], $outcome);
        $this->assertTrue($announcement->fresh()->isPublished());

        Mail::assertSent(AnnouncementMail::class, 2);
        Mail::assertSent(AnnouncementMail::class, fn ($m) => $m->hasTo('a@example.com') && $m->subjectLine === 'Resumption Notice');

        $this->assertSame(2, CommunicationLog::where('channel', 'email')->where('status', 'sent')->count());
        $this->assertSame(2, $announcement->logs()->count());
    }

    public function test_email_channel_can_be_disabled(): void
    {
        Mail::fake();
        config(['skulos.communication_channels.email.enabled' => false]);

        $announcement = Announcement::create(['title' => 'Hi', 'body' => 'Test', 'audience' => 'all']);
        $outcome = app(CommunicationService::class)->publishAnnouncement($announcement, ['x@example.com']);

        $this->assertSame(['sent' => 0, 'failed' => 0], $outcome);
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

    public function test_one_off_email_is_logged(): void
    {
        Mail::fake();

        $outcome = app(CommunicationService::class)->sendEmail(
            ['parent@example.com'], 'Fee Reminder', 'Your term fees are due.'
        );

        $this->assertSame(['sent' => 1, 'failed' => 0], $outcome);
        Mail::assertSent(AnnouncementMail::class, 1);
        $this->assertSame(1, CommunicationLog::where('subject', 'Fee Reminder')->where('status', 'sent')->count());
    }
}
