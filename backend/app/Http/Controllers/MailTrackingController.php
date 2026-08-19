<?php

namespace App\Http\Controllers;

use App\Models\MailLog;
use Illuminate\Http\Response;

/**
 * The open-tracking pixel (2026-08-19). No auth, no CSRF -- this is
 * requested by a recipient's email client, not a signed-in browser
 * session, and is protected instead by the route's own signature (see
 * routes/web.php) so a stranger cannot mark arbitrary rows as opened by
 * guessing ids.
 *
 * Deliberately honest about what this can and cannot prove: many mail
 * clients block remote images by default, meaning a real open often
 * goes undetected, while Apple Mail's Privacy Protection pre-fetches
 * every image in every message automatically regardless of whether the
 * person ever opens it, meaning some "opens" here are not real reads
 * at all. This is the best available signal without a dedicated email
 * service provider's own tracking API, not a guarantee.
 */
class MailTrackingController extends Controller
{
    /**
     * The smallest valid GIF byte-for-byte -- a real transparent 1x1
     * pixel, not a placeholder that happens to be small. Kept as a raw
     * binary constant rather than a file on disk: it never changes, and
     * this is the only place it is used.
     */
    protected const PIXEL = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF\x21\xF9\x04\x01\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B";

    public function pixel(MailLog $mailLog): Response
    {
        // First open only: opened_at records WHEN it was first opened,
        // not a running "last seen" that a later reload would overwrite.
        if (! $mailLog->opened_at) {
            $mailLog->forceFill(['opened_at' => now()])->save();
        }

        return response(self::PIXEL, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => (string) strlen(self::PIXEL),
            // Every load must reach this route, or a cached response
            // from a shared/proxy cache could mask a genuine later open.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
