<?php

namespace Tests\Feature;

use App\Mail\TestMail;
use App\Models\MailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The "Read/Open" status (2026-08-19, direct question: "is it possible
 * to have a read status along with our sent mails. Sent, Delivered,
 * Not delivered, Read/Open"). A best-effort signal via a tracking
 * pixel embedded automatically in every HTML mail, not a guarantee --
 * see RecordSentMail::injectTrackingPixel()'s own docblock for why.
 */
class MailOpenTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_html_mail_embeds_a_working_tracking_pixel(): void
    {
        config(['mail.default' => 'array']);

        Mail::to('parent@example.com')->send(new TestMail);

        $log = MailLog::first();
        $this->assertNotNull($log);
        // The 'array' mail driver used in this test sends synchronously,
        // so MessageSent has already fired and moved this to 'delivered'
        // by the time this line runs -- 'sent' only shows for a message
        // genuinely still in flight, which this one already is not.
        $this->assertSame('delivered', $log->readStatus());

        $pixelUrl = URL::signedRoute('mail.track', ['mailLog' => $log->id]);
        $response = $this->get($pixelUrl);

        $response->assertOk();
        $this->assertSame('image/gif', $response->headers->get('Content-Type'));

        $this->assertSame('opened', $log->fresh()->readStatus());
        $this->assertNotNull($log->fresh()->opened_at);
    }

    public function test_a_second_pixel_request_does_not_move_the_first_open_time(): void
    {
        $log = MailLog::create(['recipient' => 'x@example.com', 'status' => MailLog::STATUS_SENT]);
        $pixelUrl = URL::signedRoute('mail.track', ['mailLog' => $log->id]);

        $this->get($pixelUrl);
        $firstOpen = $log->fresh()->opened_at;

        $this->travel(1)->hours();
        $this->get($pixelUrl);

        $this->assertTrue($firstOpen->equalTo($log->fresh()->opened_at));
    }

    public function test_a_tampered_pixel_url_is_rejected(): void
    {
        // Both rows are real, so route model binding succeeds either
        // way -- what is actually under test is the signature itself,
        // computed for log A but presented against log B's id. Using a
        // real second id rather than a nonexistent one isolates that: a
        // nonexistent id 404s from route model binding before the
        // signature is even checked, which would prove nothing about
        // whether tampering is actually caught.
        $logA = MailLog::create(['recipient' => 'a@example.com', 'status' => MailLog::STATUS_SENT]);
        $logB = MailLog::create(['recipient' => 'b@example.com', 'status' => MailLog::STATUS_SENT]);

        $urlForA = URL::signedRoute('mail.track', ['mailLog' => $logA->id]);
        $tampered = preg_replace('#/mail/track/\d+\.gif#', '/mail/track/'.$logB->id.'.gif', $urlForA);

        $this->get($tampered)->assertForbidden();
        $this->assertNull($logA->fresh()->opened_at);
        $this->assertNull($logB->fresh()->opened_at);
    }

    public function test_read_status_reports_the_four_real_states(): void
    {
        $sending = MailLog::create(['recipient' => 'a@example.com', 'status' => MailLog::STATUS_SENDING]);
        $sent = MailLog::create(['recipient' => 'b@example.com', 'status' => MailLog::STATUS_SENT]);
        $failed = MailLog::create(['recipient' => 'c@example.com', 'status' => MailLog::STATUS_FAILED]);
        $opened = MailLog::create(['recipient' => 'd@example.com', 'status' => MailLog::STATUS_SENT, 'opened_at' => now()]);

        $this->assertSame('sent', $sending->readStatus());
        $this->assertSame('delivered', $sent->readStatus());
        $this->assertSame('not_delivered', $failed->readStatus());
        $this->assertSame('opened', $opened->readStatus());
    }
}
