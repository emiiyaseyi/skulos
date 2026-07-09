<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_root_redirects_guests_to_login(): void
    {
        // The platform entry point sends guests to sign in.
        $this->get('http://localhost/')->assertRedirect('/login');
    }
}
