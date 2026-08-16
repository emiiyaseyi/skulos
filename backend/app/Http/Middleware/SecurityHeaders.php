<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security response headers (2026-08-15, added after a live
 * check of the production site found none of them present).
 *
 * Deliberately NOT a full Content-Security-Policy: this app is built
 * with inline <style> blocks and inline <script> on nearly every Blade
 * view, so any policy strict enough to be worth having would break the
 * UI wholesale. Locking that down means moving those inline blocks to
 * real asset files first, which is a much larger change than this one,
 * so it is flagged rather than half-applied. frame-ancestors is the one
 * genuinely valuable piece available without that work, and
 * X-Frame-Options covers it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Clickjacking: without this, any site can iframe a school
        // portal and overlay invisible controls on top of it.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Stops a browser second-guessing a declared Content-Type,
        // which is how an uploaded file that claims to be an image can
        // end up executed as something else.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Report card and student URLs carry real record ids; without
        // this the full URL is sent as the Referer to any third party
        // the page links out to.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Camera/microphone/display-capture are explicitly allowed for
        // this origin, NOT blanket-denied: the CBT proctoring wizard
        // genuinely needs all three (face match, audio check, screen
        // share). Everything else a page has no business asking for is
        // turned off.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(self), microphone=(self), display-capture=(self), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=()'
        );

        // HSTS only over a real HTTPS request, and only in production:
        // sending it from a plain-HTTP local dev environment would pin
        // localhost to HTTPS in the developer's own browser and lock
        // them out of it. includeSubDomains is safe here because every
        // school subdomain is covered by the same wildcard certificate.
        if ($request->secure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
