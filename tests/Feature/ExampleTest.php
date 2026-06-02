<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_exposes_the_framework_health_endpoint(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
