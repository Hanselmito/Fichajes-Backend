<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthcheckTest extends TestCase
{
    public function test_healthcheck_reports_database_status(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('checks.database.status', 'up');
    }
}