<?php

namespace Tests\Feature;

use App\Mail\AnnouncementMail;
use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\Notification;
use App\Services\Communication\NotificationService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'notify', 'name' => 'Notify High']));
        app(TenantManager::class)->use($school);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function recipients(): array
    {
        return [
            ['user_id' => 1, 'email' => 'parent@example.com', 'audience' => 'parents'],
            ['user_id' => 2, 'email' => 'teacher@example.com', 'audience' => 'teachers'],
            ['user_id' => 3, 'email' => 'staff@example.com', 'audience' => 'staff'],
            ['user_id' => 4, 'email' => 'student@example.com', 'audience' => 'students'],
        ];
    }

    public function test_everyone_gets_an_in_app_notification_and_all_audiences_email_by_default(): void
    {
        Mail::fake();
        $announcement = Announcement::create(['title' => 'Resumption', 'body' => 'Monday.', 'audience' => 'all']);

        $result = app(NotificationService::class)->dispatchAnnouncement($announcement, $this->recipients());

        $this->assertSame(4, $result['in_app']);
        $this->assertSame(4, $result['email_sent']);
        $this->assertSame(4, Notification::count());
        Mail::assertSent(AnnouncementMail::class, 4);
    }

    public function test_admin_can_turn_off_email_for_an_audience_but_in_app_still_reaches_them(): void
    {
        Mail::fake();
        $service = app(NotificationService::class);
        $service->setAudienceEmail('students', false); // students: no email
        $service->setAudienceEmail('staff', false);    // staff: no email

        $announcement = Announcement::create(['title' => 'Notice', 'body' => 'Body', 'audience' => 'all']);
        $result = $service->dispatchAnnouncement($announcement, $this->recipients());

        // Everyone still gets in-app.
        $this->assertSame(4, $result['in_app']);
        $this->assertSame(4, Notification::count());

        // Only parents + teachers get email.
        $this->assertSame(2, $result['email_sent']);
        Mail::assertSent(AnnouncementMail::class, fn ($m) => $m->hasTo('parent@example.com'));
        Mail::assertSent(AnnouncementMail::class, fn ($m) => $m->hasTo('teacher@example.com'));
        Mail::assertNotSent(AnnouncementMail::class, fn ($m) => $m->hasTo('student@example.com'));
        Mail::assertNotSent(AnnouncementMail::class, fn ($m) => $m->hasTo('staff@example.com'));
    }

    public function test_dashboard_feed_shows_notifications_and_unread_count(): void
    {
        Mail::fake();
        $announcement = Announcement::create(['title' => 'Hello', 'body' => 'World', 'audience' => 'all']);
        app(NotificationService::class)->dispatchAnnouncement($announcement, $this->recipients());

        $feed = Notification::feedFor(1);
        $this->assertSame(1, $feed['unread']);
        $this->assertCount(1, $feed['items']);

        $feed['items']->first()->markRead();
        $this->assertSame(0, Notification::feedFor(1)['unread']);
    }

    public function test_dispatch_is_audited(): void
    {
        Mail::fake();
        $announcement = Announcement::create(['title' => 'A', 'body' => 'B', 'audience' => 'all']);
        app(NotificationService::class)->dispatchAnnouncement($announcement, $this->recipients());

        $this->assertTrue(
            \App\Models\AuditLog::on('tenant')->where('action', 'announcement.dispatched')->exists()
        );
    }
}
