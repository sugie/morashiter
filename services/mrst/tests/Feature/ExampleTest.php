<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_health_endpoint_is_available_without_an_admin_session(): void
    {
        $this->get('/up')->assertOk();
        $this->get('/')->assertNotFound();
    }
}
