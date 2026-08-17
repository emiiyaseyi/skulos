<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_root_serves_the_marketing_site_to_a_guest(): void
    {
        // '/' has served the marketing site directly to a guest since
        // 2026-08-14, not a redirect to /login (see the identical fix
        // in PlatformFlowTest for the same stale assumption).
        $this->get('http://localhost/')->assertOk();
    }
}
