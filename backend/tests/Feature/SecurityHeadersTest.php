<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the security response headers (2026-08-15). Written after a
 * live check of production found none of them present at all.
 *
 * The Content-Security-Policy assertions matter most: several of its
 * directives look like they could be tightened, and tightening the
 * wrong one silently breaks a real feature rather than failing loudly.
 * Each of those is asserted here with the reason, so a future
 * "hardening" pass has to consciously delete a test rather than
 * discover the breakage from a user.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function csp(): string
    {
        return $this->get('/login')->headers->get('Content-Security-Policy') ?? '';
    }

    public function test_the_baseline_hardening_headers_are_present(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function test_proctoring_permissions_are_allowed_not_blanket_denied(): void
    {
        $policy = $this->get('/login')->headers->get('Permissions-Policy');

        // CBT proctoring genuinely needs all three; denying them would
        // break face match, the audio check and screen share.
        $this->assertStringContainsString('camera=(self)', $policy);
        $this->assertStringContainsString('microphone=(self)', $policy);
        $this->assertStringContainsString('display-capture=(self)', $policy);
    }

    public function test_the_csp_blocks_the_things_it_is_actually_there_to_block(): void
    {
        $csp = $this->csp();

        // Injected code cannot be loaded from, or send data to, an
        // outside host, and an injected form cannot post credentials
        // away from this origin.
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_the_csp_still_permits_what_cbt_proctoring_needs(): void
    {
        $csp = $this->csp();

        // ONNX Runtime compiles WebAssembly for the object-detection
        // model; without this, live proctoring stops working silently.
        $this->assertStringContainsString("'wasm-unsafe-eval'", $csp);
        // Camera capture and the model workers both use blob: URLs.
        $this->assertStringContainsString('media-src', $csp);
        $this->assertStringContainsString('worker-src', $csp);
        $this->assertStringContainsString('blob:', $csp);
    }

    public function test_inline_script_and_style_are_still_permitted_deliberately(): void
    {
        $csp = $this->csp();

        // Not an oversight: this app has hundreds of inline handlers and
        // thousands of inline style attributes. Removing 'unsafe-inline'
        // without first moving them out breaks the UI wholesale, so this
        // asserts the current, deliberate state rather than an aspiration.
        $this->assertStringContainsString("'unsafe-inline'", $csp);
    }
}
