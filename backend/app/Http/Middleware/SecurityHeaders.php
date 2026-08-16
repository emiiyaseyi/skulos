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

        // Content-Security-Policy. Be clear about what this does and
        // does not do: 'unsafe-inline' stays for scripts and styles,
        // because this app has 225 inline event handlers, 86 views with
        // inline <script>, 70 with inline <style> and ~2,900 inline
        // style attributes. A policy strict enough to stop inline XSS
        // would break every one of them, and rushing that refactor on a
        // live platform is how a site goes down. So this is NOT XSS
        // protection.
        //
        // What it genuinely does stop, all of which are real and worth
        // having on their own:
        //  - script-src 'self': an injected <script src="//evil.com/x.js">
        //    will not load. Only this origin can serve executable code.
        //  - connect-src 'self': injected code cannot exfiltrate student
        //    data to an outside host via fetch/XHR/WebSocket.
        //  - form-action 'self': an injected form cannot post a parent's
        //    credentials to an attacker's domain.
        //  - base-uri 'self': stops a <base> tag hijacking every relative
        //    URL on the page.
        //  - object-src 'none': removes the plugin/embed vector entirely.
        //  - frame-ancestors 'self': clickjacking, matching the
        //    X-Frame-Options above for browsers that prefer CSP.
        //
        // Verified before enabling: the application references no
        // external host at all (the only one in the codebase is
        // Laravel's stock, unrouted welcome.blade.php), so 'self' does
        // not cut off anything real.
        //
        // 'wasm-unsafe-eval' is required, not incidental: CBT proctoring
        // runs YOLOv5n through ONNX Runtime, which compiles WebAssembly.
        // Without it, live proctoring silently stops working. blob: on
        // media-src and worker-src is needed for the same feature's
        // camera capture and its model workers.
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));

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
